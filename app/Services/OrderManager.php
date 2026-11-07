<?php

namespace App\Services;

use App\Enums\DistributionState;
use App\Enums\OrderOrigin;
use App\Enums\SiteRequestState;
use App\Exceptions\MangoEventFailure;
use App\Exceptions\SiteRequestFailure;
use App\Models\Call;
use App\Models\Client;
use App\Models\ClientPhone;
use App\Models\ContactChannel;
use App\Models\Order;
use App\Models\OrderComment;
use App\Models\OrderDropReason;
use App\Models\OrderPhone;
use App\Models\OrderStatus;
use App\Models\OrderTrashReason;
use App\Models\PaymentMethod;
use App\Models\Showroom;
use App\Models\SiteCallbackRequest;
use App\Models\Source;
use App\Models\User;
use App\Models\VisitResult;
use App\Services\Concerns\HandlesUniqueConstraints;
use App\Support\OrderReasonType;
use App\Support\Phone;
use App\Support\PermissionCatalog as P;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * `phones` are normalized numbers, the main one first; `phone_names` maps a number to
 * the name it was given. `responsible_id` is present only when the actor may choose;
 * `assign_any` widens the choice from the order's showroom to every showroom.
 *
 * @phpstan-type CreateData array{showroom_id?: int|null, source_id: int, contact_channel_id: int, payment_method_id?: int|null, contact_name: string|null, phones: list<string>, phone_names: array<string, string|null>, car_interest: string|null, order_status_id?: int|null, callback_at?: string|null, arrival_at?: string|null, visit_result_id?: int|null, reason_id?: int|null, reason_comment?: string|null, comment?: string|null, responsible_id?: int|null, assign_any?: bool}
 * @phpstan-type UpdateData array{contact_channel_id: int, payment_method_id?: int|null, contact_name: string|null, phones: list<string>, phone_names: array<string, string|null>, car_interest: string|null, order_status_id: int, callback_at: string|null, arrival_at: string|null, visit_result_id?: int|null, reason_id: int|null, reason_comment: string|null, lock_version: int, responsible_id?: int|null, assign_any?: bool}
 */
class OrderManager
{
    use HandlesUniqueConstraints;

    public const PHONE_CONFLICT = 'Номера относятся к разным клиентам. Оставьте номера одного клиента: автоматическое объединение клиентов не выполняется.';

    public const PHONE_TAKEN = 'Номер относится к другому клиенту. Автоматическое объединение клиентов не выполняется.';

    public const RESPONSIBLE_INVALID = 'Выберите активного сотрудника, которого вам разрешено назначить.';

    public const PAYMENT_METHOD_INVALID = 'Выберите активный способ оплаты.';

    public const VISIT_RESULT_INVALID = 'Выберите активный итог визита.';

    public const ALREADY_TAKEN = 'Заявку уже взял в работу или назначил другой сотрудник. Обновите страницу.';

    public const DATETIME_FORMAT = 'Y-m-d\TH:i';

    /** The author name of a comment the client left in a site form. */
    public const SITE_AUTHOR = 'Сайт';

    private const LABELS_FORMAT = 'd.m.Y H:i';

    /** Indexes whose violation means a concurrent request won the race: the creation is retried. */
    private const RETRY_INDEXES = ['client_phones_phone_unique', 'orders_client_showroom_repeat_unique'];

    public function __construct(
        private readonly AuditLogger $audit,
        private readonly BusinessDayCalculator $businessDays,
        private readonly AccessScope $scope,
    ) {}

    /**
     * Manual creation always creates a new order. Its showroom is the showroom of the checked
     * source; a showroom chosen in the form only has to match it. The client is locked before the earlier
     * orders of the showroom are counted, so concurrent orders of one client get distinct
     * repeat numbers; the unique index backs this up.
     *
     * @param  CreateData  $data
     */
    public function create(User $actor, array $data): Order
    {
        return $this->retrying(fn () => $this->createOrder($actor, $data));
    }

    /**
     * Creates the order of an accepted site request and links it to the request in one
     * transaction, so a request never gets two orders; a request that already has its
     * order returns it. Nobody is the author: the order starts without a responsible,
     * handed to callback distribution, and its times, business date and rule come from
     * the request. Returns null when the request is no longer waiting for processing.
     */
    public function createFromSiteRequest(int $requestId): ?Order
    {
        return $this->audit->asSystem('site_api', fn () => $this->retrying(fn () => $this->createSiteOrder($requestId)));
    }

    /**
     * The order a call of this showroom belongs to: the number is the main or an
     * additional number of the order, or a number of the order's client. Of several the
     * last changed one wins, then the higher id. Only the call handler uses this; nothing
     * found here is shown to an employee without the usual access checks.
     */
    public function findForCall(int $showroomId, string $phone): ?Order
    {
        $clientId = ClientPhone::query()->where('phone', $phone)->value('client_id');

        return Order::query()
            ->where('showroom_id', $showroomId)
            ->where(fn (Builder $query) => $query
                ->where('phone', $phone)
                ->orWhereHas('additionalPhones', fn (Builder $phones) => $phones->where('phone', $phone))
                ->when($clientId !== null, fn (Builder $byClient) => $byClient->orWhere('client_id', $clientId)))
            ->orderByDesc('updated_at')
            ->orderByDesc('id')
            ->first();
    }

    /**
     * Creates the order of an incoming call. Runs inside the caller's transaction, which
     * holds the call row: the caller retries the whole transaction when a concurrent call
     * of the same client wins the unique indexes. Times and the business date come from
     * the call, never from the moment of processing; direct calls are not distributed.
     */
    public function createForCall(Call $call, Source $source, int $channelId, ?User $responsible, CarbonImmutable $receivedAt): Order
    {
        $status = OrderStatus::query()->where('is_initial', true)->where('active', true)->sharedLock()->first()
            ?? throw new MangoEventFailure('initial_status_missing');

        $phone = (string) $call->client_phone;
        $eventAt = $call->started_at ?? $receivedAt;
        $client = $this->resolveClient([$phone], null);
        $repeat = Order::query()->where('client_id', $client->id)->where('showroom_id', $source->showroom_id)->count();
        $day = $this->businessDays->forShowroom($source->showroom, $eventAt);

        $order = new Order([
            'client_id' => $client->id,
            'showroom_id' => $source->showroom_id,
            'source_id' => $source->id,
            'contact_channel_id' => $channelId,
            'origin' => OrderOrigin::MangoVpbx,
            'contact_name' => null,
            'phone' => $phone,
            'order_status_id' => $status->id,
            'responsible_id' => $responsible?->id,
            'event_at' => $eventAt,
            'received_at' => $receivedAt,
            'business_date' => $day->date,
            'business_day_rule' => $day->rule->snapshot(),
            'repeat_number' => $repeat,
            'created_by' => null,
        ]);
        $order->save();

        $this->audit->asSystem(MangoCallService::BASIS, fn () => $this->audit->record('order.created', 'order', $order->id, $this->label($order), 'Создана заявка по входящему звонку', array_filter([
            'origin' => OrderOrigin::MangoVpbx->label(),
            'showroom' => $source->showroom->name,
            'source' => $source->name,
            'channel' => ContactChannel::query()->whereKey($channelId)->value('name'),
            'status' => $status->name,
            'responsible' => $responsible?->name,
            'phones' => Phone::format($phone),
            'business_date' => $day->date,
            'repeat_number' => $repeat,
        ], fn ($value) => $value !== null), [$order->showroom_id]));

        return $order;
    }

    /**
     * The employee who answered a call takes an order that has no responsible. The check
     * and the change happen under the order lock every other assignment (manual, take,
     * distribution, transfer) also takes, so a responsible set by anyone in the meantime
     * is never replaced. Returns whether the order was assigned.
     */
    public function assignFromCall(int $orderId, User $user): bool
    {
        /** @var Order $locked */
        $locked = Order::query()->lockForUpdate()->findOrFail($orderId);

        if ($locked->responsible_id !== null) {
            return false;
        }

        $locked->responsible_id = $user->id;
        $locked->distribution_state = null;
        $locked->bumpLockVersion()->save();

        $this->audit->asSystem(MangoCallService::BASIS, fn () => $this->audit->record('order.responsible_changed', 'order', $locked->id, $this->label($locked), 'Ответственный — сотрудник, ответивший на звонок', [
            'responsible' => [null, $user->name],
        ], [$locked->showroom_id]));

        return true;
    }

    /**
     * @param  UpdateData  $data
     */
    public function update(Order $order, array $data): Order
    {
        return $this->withUniqueField('client_phones_phone_unique', 'phones', self::PHONE_TAKEN, fn () => DB::transaction(function () use ($order, $data) {
            /** @var Order $locked */
            $locked = Order::query()->lockForUpdate()->findOrFail($order->id);
            $locked->ensureLockVersion($data['lock_version']);
            $locked->load(['showroom', 'channel', 'paymentMethod', 'visitResult', 'status', 'responsible', 'trashReason', 'dropReason', 'additionalPhones']);

            $timezone = $locked->showroom->timezone;
            $phones = $this->uniquePhones($data['phones']);
            $phonesBefore = array_column($locked->contactPhones(), 'phone');
            $namesBefore = $locked->additionalPhones->pluck('name', 'phone')->all();
            $before = $this->snapshot($locked, $phonesBefore, $namesBefore);
            $errors = [];

            $status = OrderStatus::query()->sharedLock()->find($data['order_status_id']);
            $statusChanged = $status?->id !== $locked->order_status_id;

            if ($status === null || ($statusChanged && ! $status->active)) {
                $errors['order_status_id'] = 'Выберите активный статус.';
            } elseif ($statusChanged && $status->purpose !== null) {
                $errors['order_status_id'] = "Статус «{$status->name}» ставится системой: {$status->purpose->label()}.";
            }

            if ($data['contact_channel_id'] !== $locked->contact_channel_id && ! $this->channelAllowed($locked->source_id, $data['contact_channel_id'])) {
                $errors['contact_channel_id'] = 'Тип заявки отключён или не разрешён для источника заявки.';
            }

            $paymentMethodId = array_key_exists('payment_method_id', $data) ? $data['payment_method_id'] : $locked->payment_method_id;

            if ($paymentMethodId !== $locked->payment_method_id && ! $this->paymentMethodAllowed($paymentMethodId)) {
                $errors['payment_method_id'] = self::PAYMENT_METHOD_INVALID;
            }

            $visitResultId = array_key_exists('visit_result_id', $data) ? $data['visit_result_id'] : $locked->visit_result_id;

            if ($visitResultId !== $locked->visit_result_id && ! $this->visitResultAllowed($visitResultId)) {
                $errors['visit_result_id'] = self::VISIT_RESULT_INVALID;
            }

            $callbackAt = $this->parseLocal($data['callback_at'], $timezone);
            $arrivalAt = $this->parseLocal($data['arrival_at'], $timezone);

            $locked->fill([
                'contact_channel_id' => $data['contact_channel_id'],
                'payment_method_id' => $paymentMethodId,
                'contact_name' => $data['contact_name'],
                'phone' => $phones[0],
                'car_interest' => $data['car_interest'],
                'order_status_id' => $status?->id ?? $locked->order_status_id,
                'callback_at' => $callbackAt,
                'arrival_at' => $arrivalAt,
                'visit_result_id' => $visitResultId,
            ]);

            if ($status !== null) {
                $errors += $this->applyRequirements($locked, $status, $statusChanged, $data);
            }

            if (array_key_exists('responsible_id', $data) && $data['responsible_id'] !== $locked->responsible_id) {
                if ($data['responsible_id'] !== null && ! $this->responsibleCandidates($locked->showroom_id, $data['assign_any'] ?? false)->whereKey($data['responsible_id'])->exists()) {
                    $errors['responsible_id'] = self::RESPONSIBLE_INVALID;
                }

                $locked->responsible_id = $data['responsible_id'];

                if ($data['responsible_id'] !== null) {
                    $locked->distribution_state = null;
                }
            }

            $phonesChanged = $phones !== $phonesBefore;
            $namesChanged = $this->names($phones, $data['phone_names']) !== $this->names($phonesBefore, $namesBefore);

            if ($phonesChanged && $errors === []) {
                $this->attachPhones(Client::query()->lockForUpdate()->findOrFail($locked->client_id), $phones, true);
            }

            if ($errors !== []) {
                throw ValidationException::withMessages($errors);
            }

            if (! $locked->isDirty() && ! $phonesChanged && ! $namesChanged) {
                return $locked;
            }

            $locked->bumpLockVersion()->save();

            if ($phonesChanged || $namesChanged) {
                $this->syncAdditionalPhones($locked, $phones, $data['phone_names']);
            }

            $locked->unsetRelations()->load(['channel', 'paymentMethod', 'visitResult', 'status', 'responsible', 'trashReason', 'dropReason']);
            $after = $this->snapshot($locked, $phones, $data['phone_names']);
            $this->recordChanges($locked, $before, $after);

            return $locked;
        }));
    }

    /**
     * The employee takes an unassigned order himself. The order row is locked, so of two
     * concurrent takes or a take and a manual assignment only the first one wins; the
     * other gets a conflict and nothing is overwritten. The schedule does not matter here.
     */
    public function take(Order $order, User $user): Order
    {
        return DB::transaction(function () use ($order, $user) {
            /** @var Order $locked */
            $locked = Order::query()->lockForUpdate()->findOrFail($order->id);

            if ($locked->responsible_id !== null) {
                throw ValidationException::withMessages(['responsible_id' => self::ALREADY_TAKEN]);
            }

            if (! $this->responsibleCandidates($locked->showroom_id)->whereKey($user->id)->permission(P::ORDERS_VIEW)->exists()) {
                throw ValidationException::withMessages(['responsible_id' => self::RESPONSIBLE_INVALID]);
            }

            $locked->responsible_id = $user->id;
            $locked->distribution_state = null;
            $locked->bumpLockVersion()->save();

            $this->audit->record('order.responsible_changed', 'order', $locked->id, $this->label($locked), 'Заявка взята в работу', [
                'responsible' => [null, $user->name],
            ], [$locked->showroom_id]);

            return $locked;
        });
    }

    public function addComment(Order $order, User $author, string $body): OrderComment
    {
        return DB::transaction(function () use ($order, $author, $body) {
            $comment = OrderComment::create([
                'order_id' => $order->id,
                'author_id' => $author->id,
                'author_name' => $author->name,
                'body' => $body,
            ]);

            $this->audit->record('order.comment_added', 'order', $order->id, $this->label($order), 'Добавлен комментарий', [
                'comment_id' => $comment->id,
            ], [$order->showroom_id]);

            return $comment;
        });
    }

    public function recordView(Order $order): void
    {
        $this->audit->record('order.viewed', 'order', $order->id, $this->label($order), 'Открыта карточка заявки', [], [$order->showroom_id]);
    }

    /**
     * Active employees allowed to process orders: with access to the showroom, or from
     * every showroom when the actor may assign anyone. A disabled employee stays on old
     * orders but is never offered for a new assignment.
     *
     * @return Builder<User>
     */
    public function responsibleCandidates(?int $showroomId, bool $anyShowroom = false): Builder
    {
        return User::query()
            ->where('active', true)
            ->permission(P::ORDERS_UPDATE)
            ->when(! $anyShowroom, fn (Builder $query) => $query->where(fn (Builder $inner) => $inner
                ->where('has_global_access', true)
                ->orWhereHas('showrooms', fn (Builder $showrooms) => $showrooms->where('showrooms.id', $showroomId))));
    }

    /**
     * @return Builder<OrderTrashReason>|Builder<OrderDropReason>
     */
    public function availableReasons(OrderReasonType $type, int $showroomId): Builder
    {
        return $type->model()::query()
            ->where('active', true)
            ->where(fn (Builder $query) => $query
                ->where('all_showrooms', true)
                ->orWhereHas('showrooms', fn (Builder $showrooms) => $showrooms->where('showrooms.id', $showroomId)));
    }

    public function formatLocal(?CarbonImmutable $moment, string $timezone, string $format = self::DATETIME_FORMAT): ?string
    {
        return $moment?->setTimezone($timezone)->format($format);
    }

    /**
     * @param  CreateData  $data
     */
    private function createOrder(User $actor, array $data): Order
    {
        $showroomId = $data['showroom_id'] ?? null;

        if ($showroomId !== null && ! $this->scope->canAccessShowroom($actor, $showroomId)) {
            throw ValidationException::withMessages(['showroom_id' => 'Выберите салон из вашей области доступа.']);
        }

        if ($showroomId === null) {
            $available = $this->scope->showrooms(Showroom::query(), $actor)->where('active', true)->limit(2)->pluck('id');

            if ($available->count() !== 1) {
                throw ValidationException::withMessages(['showroom_id' => 'Выберите салон.']);
            }

            $showroomId = $available->first();
        }

        $source = Source::query()->with('showroom')->sharedLock()->find($data['source_id']);

        if ($source === null || ! $source->active || ! $this->scope->canAccessShowroom($actor, $source->showroom_id)) {
            throw ValidationException::withMessages(['source_id' => 'Выберите активный источник доступного салона.']);
        }

        if ($source->showroom_id !== $showroomId) {
            throw ValidationException::withMessages(['source_id' => 'Источник не относится к выбранному салону.']);
        }

        if ($source->transfer_from_showroom_id !== null) {
            throw ValidationException::withMessages(['source_id' => 'Служебный источник передачи между салонами нельзя выбрать при ручном создании.']);
        }

        if (! $source->showroom->active) {
            throw ValidationException::withMessages(['source_id' => 'Салон этого источника отключён.']);
        }

        if (! $this->channelAllowed($source->id, $data['contact_channel_id'])) {
            throw ValidationException::withMessages(['contact_channel_id' => 'Тип заявки отключён или не разрешён для выбранного источника.']);
        }

        $paymentMethodId = $data['payment_method_id'] ?? null;

        if ($paymentMethodId !== null && ! $this->paymentMethodAllowed($paymentMethodId)) {
            throw ValidationException::withMessages(['payment_method_id' => self::PAYMENT_METHOD_INVALID]);
        }

        $visitResultId = $data['visit_result_id'] ?? null;

        if (! $this->visitResultAllowed($visitResultId)) {
            throw ValidationException::withMessages(['visit_result_id' => self::VISIT_RESULT_INVALID]);
        }

        $statusId = $data['order_status_id'] ?? null;
        $status = $statusId === null
            ? OrderStatus::query()->where('is_initial', true)->where('active', true)->sharedLock()->first()
            : OrderStatus::query()->sharedLock()->find($statusId);

        if ($status === null && $statusId === null) {
            throw ValidationException::withMessages(['order' => 'Начальный статус заявок не выбран. Обратитесь к администратору справочников.']);
        }

        if ($status === null || ! $status->active) {
            throw ValidationException::withMessages(['order_status_id' => 'Выберите активный статус.']);
        }

        if ($status->purpose !== null) {
            throw ValidationException::withMessages(['order_status_id' => "Статус «{$status->name}» ставится системой: {$status->purpose->label()}."]);
        }

        $chooses = array_key_exists('responsible_id', $data);
        $responsibleId = $chooses ? $data['responsible_id'] : $actor->id;

        if ($chooses && $responsibleId !== null && ! $this->responsibleCandidates($source->showroom_id, $data['assign_any'] ?? false)->whereKey($responsibleId)->exists()) {
            throw ValidationException::withMessages(['responsible_id' => self::RESPONSIBLE_INVALID]);
        }

        $timezone = $source->showroom->timezone;
        $order = new Order([
            'showroom_id' => $source->showroom_id,
            'order_status_id' => $status->id,
            'callback_at' => $this->parseLocal($data['callback_at'] ?? null, $timezone),
            'arrival_at' => $this->parseLocal($data['arrival_at'] ?? null, $timezone),
            'visit_result_id' => $visitResultId,
        ]);
        $errors = $this->applyRequirements($order, $status, true, [
            'reason_id' => $data['reason_id'] ?? null,
            'reason_comment' => $data['reason_comment'] ?? null,
        ]);

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        $phones = $this->uniquePhones($data['phones']);
        $client = $this->resolveClient($phones, $data['contact_name']);
        $repeat = Order::query()->where('client_id', $client->id)->where('showroom_id', $source->showroom_id)->count();

        $now = CarbonImmutable::now();
        $day = $this->businessDays->forShowroom($source->showroom, $now);

        $order->fill([
            'client_id' => $client->id,
            'source_id' => $source->id,
            'contact_channel_id' => $data['contact_channel_id'],
            'payment_method_id' => $paymentMethodId,
            'origin' => OrderOrigin::Manual,
            'contact_name' => $data['contact_name'],
            'phone' => $phones[0],
            'responsible_id' => $responsibleId,
            'car_interest' => $data['car_interest'],
            'event_at' => $now,
            'received_at' => $now,
            'business_date' => $day->date,
            'business_day_rule' => $day->rule->snapshot(),
            'repeat_number' => $repeat,
            'created_by' => $actor->id,
        ])->save();

        $this->syncAdditionalPhones($order, $phones, $data['phone_names']);

        $this->audit->record('order.created', 'order', $order->id, $this->label($order), 'Создана заявка', array_filter([
            'origin' => OrderOrigin::Manual->label(),
            'showroom' => $source->showroom->name,
            'source' => $source->name,
            'channel' => ContactChannel::query()->whereKey($data['contact_channel_id'])->value('name'),
            'payment_method' => $paymentMethodId === null ? null : PaymentMethod::query()->whereKey($paymentMethodId)->value('name'),
            'status' => $status->name,
            'responsible' => $responsibleId === null ? null : User::query()->whereKey($responsibleId)->value('name'),
            'contact_name' => $order->contact_name,
            'phones' => $this->phoneList($phones, $data['phone_names']),
            'car_interest' => $order->car_interest,
            'callback_at' => $this->formatLocal($order->callback_at, $timezone, self::LABELS_FORMAT),
            'arrival_at' => $this->formatLocal($order->arrival_at, $timezone, self::LABELS_FORMAT),
            'visit_result' => $visitResultId === null ? null : VisitResult::query()->whereKey($visitResultId)->value('name'),
            'reason' => $order->reason()?->name,
            'reason_comment' => $order->reason_comment,
            'business_date' => $day->date,
            'repeat_number' => $repeat,
        ], fn ($value) => $value !== null), [$order->showroom_id]);

        if (($data['comment'] ?? null) !== null) {
            $this->addComment($order, $actor, $data['comment']);
        }

        return $order;
    }

    /**
     * @template T
     *
     * @param  callable(): T  $create
     * @return T
     */
    private function retrying(callable $create): mixed
    {
        for ($attempt = 1; ; $attempt++) {
            try {
                return DB::transaction($create, 3);
            } catch (UniqueConstraintViolationException $exception) {
                $retry = collect(self::RETRY_INDEXES)->contains(fn (string $index) => str_contains($exception->getMessage(), $index));

                if (! $retry || $attempt >= 3) {
                    throw $exception;
                }
            }
        }
    }

    private function createSiteOrder(int $requestId): ?Order
    {
        /** @var SiteCallbackRequest|null $request */
        $request = SiteCallbackRequest::query()->lockForUpdate()->find($requestId);

        if ($request === null || $request->state !== SiteRequestState::Accepted) {
            return null;
        }

        if ($request->order_id !== null) {
            return Order::query()->findOrFail($request->order_id);
        }

        $status = OrderStatus::query()->where('is_initial', true)->where('active', true)->sharedLock()->first();

        if ($status === null) {
            throw new SiteRequestFailure('initial_status_missing');
        }

        $request->load(['source:id,name', 'showroom:id,name']);
        $client = $this->resolveClient([$request->phone], $request->name);
        $repeat = Order::query()->where('client_id', $client->id)->where('showroom_id', $request->showroom_id)->count();

        $order = new Order([
            'client_id' => $client->id,
            'showroom_id' => $request->showroom_id,
            'source_id' => $request->source_id,
            'contact_channel_id' => $request->contact_channel_id,
            'origin' => OrderOrigin::SiteApi,
            'contact_name' => $request->name,
            'phone' => $request->phone,
            'order_status_id' => $status->id,
            'responsible_id' => null,
            'event_at' => $request->event_at,
            'received_at' => $request->received_at,
            'business_date' => $request->business_date,
            'business_day_rule' => $request->business_day_rule,
            'repeat_number' => $repeat,
            'created_by' => null,
        ]);
        $order->distribution_state = DistributionState::Pending;
        $order->save();

        $request->order_id = $order->id;
        $request->save();

        $this->audit->record('order.created', 'order', $order->id, $this->label($order), 'Создана заявка с сайта', array_filter([
            'origin' => OrderOrigin::SiteApi->label(),
            'showroom' => $request->showroom->name,
            'source' => $request->source->name,
            'channel' => ContactChannel::query()->whereKey($request->contact_channel_id)->value('name'),
            'status' => $status->name,
            'contact_name' => $order->contact_name,
            'phones' => Phone::format($request->phone),
            'business_date' => $request->business_date,
            'repeat_number' => $repeat,
        ], fn ($value) => $value !== null), [$order->showroom_id]);

        if ($request->comment !== null) {
            $comment = OrderComment::create([
                'order_id' => $order->id,
                'author_id' => null,
                'author_name' => self::SITE_AUTHOR,
                'body' => $request->comment,
            ]);

            $this->audit->record('order.comment_added', 'order', $order->id, $this->label($order), 'Добавлен комментарий с сайта', [
                'comment_id' => $comment->id,
            ], [$order->showroom_id]);
        }

        return $order;
    }

    /**
     * Finds the client by complete numbers or creates one. Numbers of two different
     * clients are a conflict: the clients are not merged, and the message names nobody.
     * An existing client keeps its name.
     *
     * @param  list<string>  $phones
     */
    private function resolveClient(array $phones, ?string $name): Client
    {
        $owners = ClientPhone::query()->whereIn('phone', $phones)->lockForUpdate()->pluck('client_id')->unique()->values();

        if ($owners->count() > 1) {
            throw ValidationException::withMessages(['phones' => self::PHONE_CONFLICT]);
        }

        $client = $owners->isEmpty()
            ? Client::create(['name' => $name])
            : Client::query()->lockForUpdate()->findOrFail($owners->first());

        $this->attachPhones($client, $phones, false);

        return $client;
    }

    /**
     * @param  list<string>  $phones
     */
    private function attachPhones(Client $client, array $phones, bool $checkOwners): void
    {
        $owners = ClientPhone::query()->whereIn('phone', $phones)->lockForUpdate()->get(['client_id', 'phone']);

        if ($checkOwners && $owners->contains(fn (ClientPhone $phone) => $phone->client_id !== $client->id)) {
            throw ValidationException::withMessages(['phones' => self::PHONE_TAKEN]);
        }

        foreach (array_diff($phones, $owners->pluck('phone')->all()) as $phone) {
            ClientPhone::create(['client_id' => $client->id, 'phone' => $phone]);
        }
    }

    /**
     * Normalized numbers without repeats, the main one first. A repeat of the main number
     * among the additional ones disappears; the main number is required.
     *
     * @param  list<string>  $phones
     * @return non-empty-list<string>
     */
    private function uniquePhones(array $phones): array
    {
        $unique = array_values(array_unique(array_filter(array_map(Phone::normalize(...), $phones))));

        if ($unique === []) {
            throw ValidationException::withMessages(['phones' => 'Укажите основной телефон.']);
        }

        return $unique;
    }

    /**
     * Rewrites the additional numbers of one order; the main one lives in `orders.phone`.
     * Numbers dropped here stay with the client: other orders may use them.
     *
     * @param  non-empty-list<string>  $phones  the main number first
     * @param  array<string, string|null>  $names
     */
    private function syncAdditionalPhones(Order $order, array $phones, array $names): void
    {
        OrderPhone::query()->where('order_id', $order->id)->delete();

        foreach (array_slice($phones, 1) as $index => $phone) {
            OrderPhone::create([
                'order_id' => $order->id,
                'phone' => $phone,
                'name' => $names[$phone] ?? null,
                'position' => $index + 1,
            ]);
        }
    }

    /**
     * The names of the additional numbers in order, for comparing two phone lists.
     *
     * @param  list<string>  $phones
     * @param  array<string, string|null>  $names
     * @return list<string|null>
     */
    private function names(array $phones, array $names): array
    {
        return array_map(fn (string $phone) => $names[$phone] ?? null, array_slice($phones, 1));
    }

    private function channelAllowed(int $sourceId, int $channelId): bool
    {
        return DB::table('source_channel')
            ->join('contact_channels', 'contact_channels.id', '=', 'source_channel.contact_channel_id')
            ->where('source_channel.source_id', $sourceId)
            ->where('contact_channels.id', $channelId)
            ->where('contact_channels.active', true)
            ->exists();
    }

    /**
     * Requirements of the chosen status are checked when the status changes or the
     * related value is changed, so a requirement added to the status later does not
     * block unrelated edits. A reason that does not belong to the status' reason list
     * is cleared; the change goes to the audit like any other.
     *
     * @param  array{reason_id: int|null, reason_comment: string|null}  $data
     * @return array<string, string>
     */
    private function applyRequirements(Order $order, OrderStatus $status, bool $statusChanged, array $data): array
    {
        $errors = [];

        if ($status->requires_callback_at && $order->callback_at === null && ($statusChanged || $order->isDirty('callback_at'))) {
            $errors['callback_at'] = "Для статуса «{$status->name}» укажите дату и время перезвона.";
        }

        if ($status->requires_arrival_at && $order->arrival_at === null && ($statusChanged || $order->isDirty('arrival_at'))) {
            $errors['arrival_at'] = "Для статуса «{$status->name}» укажите дату и время приезда.";
        }

        if ($status->requires_visit_result && $order->visit_result_id === null && ($statusChanged || $order->isDirty('visit_result_id'))) {
            $errors['visit_result_id'] = "Для статуса «{$status->name}» выберите итог визита.";
        }

        $type = $status->required_reason;

        $reasonId = $data['reason_id'];
        $comment = $data['reason_comment'];

        if (! $statusChanged && $reasonId === $order->reasonId() && $comment === $order->reason_comment) {
            return $errors;
        }

        if ($type === null) {
            $order->fill(['order_trash_reason_id' => null, 'order_drop_reason_id' => null, 'reason_comment' => null]);

            return $errors;
        }

        $reasonChanged = $order->reasonType() !== $type || $order->reasonId() !== $reasonId;

        $reason = $reasonId === null ? null : $type->model()::query()->find($reasonId);

        if ($reason === null) {
            $errors['reason_id'] = "Для статуса «{$status->name}» выберите: {$type->singular()}.";
        } elseif ($reasonChanged && ! $this->availableReasons($type, $order->showroom_id)->whereKey($reason->id)->exists()) {
            $errors['reason_id'] = 'Причина отключена или не действует в салоне заявки.';
        } elseif ($reason->comment_required && $comment === null) {
            $errors['reason_comment'] = 'Для этой причины нужно пояснение.';
        }

        $order->fill([
            'order_trash_reason_id' => $type === OrderReasonType::Trash ? $reasonId : null,
            'order_drop_reason_id' => $type === OrderReasonType::Drop ? $reasonId : null,
            'reason_comment' => $comment,
        ]);

        return $errors;
    }

    /**
     * @param  array<string, string|null>  $before
     * @param  array<string, string|null>  $after
     */
    private function recordChanges(Order $order, array $before, array $after): void
    {
        $changes = [];

        foreach ($after as $field => $value) {
            if ($before[$field] !== $value) {
                $changes[$field] = [$before[$field], $value];
            }
        }

        $label = $this->label($order);
        $showrooms = [$order->showroom_id];

        if (isset($changes['status'])) {
            $this->audit->record('order.status_changed', 'order', $order->id, $label, 'Изменён статус заявки', ['status' => $changes['status']], $showrooms);
        }

        if (isset($changes['responsible'])) {
            $this->audit->record('order.responsible_changed', 'order', $order->id, $label, 'Назначен ответственный', ['responsible' => $changes['responsible']], $showrooms);
        }

        $rest = array_diff_key($changes, ['status' => true, 'responsible' => true]);

        if ($rest !== []) {
            $this->audit->record('order.updated', 'order', $order->id, $label, 'Изменена заявка', $rest, $showrooms);
        }
    }

    /**
     * Readable values for the history: names instead of ids, local showroom time.
     *
     * @param  list<string>  $phones
     * @param  array<string, string|null>  $names
     * @return array<string, string|null>
     */
    private function snapshot(Order $order, array $phones, array $names): array
    {
        $timezone = $order->showroom->timezone;

        return [
            'contact_name' => $order->contact_name,
            'phones' => $this->phoneList($phones, $names),
            'channel' => $order->channel->name,
            'payment_method' => $order->paymentMethod?->name,
            'status' => $order->status->name,
            'responsible' => $order->responsible?->name,
            'callback_at' => $this->formatLocal($order->callback_at, $timezone, self::LABELS_FORMAT),
            'arrival_at' => $this->formatLocal($order->arrival_at, $timezone, self::LABELS_FORMAT),
            'visit_result' => $order->visitResult?->name,
            'car_interest' => $order->car_interest,
            'reason' => $order->reason()?->name,
            'reason_comment' => $order->reason_comment,
        ];
    }

    private function paymentMethodAllowed(?int $id): bool
    {
        return $id === null || PaymentMethod::query()->whereKey($id)->where('active', true)->sharedLock()->exists();
    }

    private function visitResultAllowed(?int $id): bool
    {
        return $id === null || VisitResult::query()->whereKey($id)->where('active', true)->sharedLock()->exists();
    }

    private function parseLocal(?string $value, string $timezone): ?CarbonImmutable
    {
        return $value === null ? null : CarbonImmutable::createFromFormat(self::DATETIME_FORMAT, $value, $timezone)?->utc();
    }

    /**
     * @param  list<string>  $phones
     * @param  array<string, string|null>  $names
     */
    private function phoneList(array $phones, array $names): string
    {
        return implode(', ', array_map(function (string $phone, int $position) use ($names): string {
            $name = $position === 0 ? null : ($names[$phone] ?? null);

            return Phone::format($phone).($name === null ? '' : " ({$name})");
        }, $phones, array_keys($phones)));
    }

    private function label(Order $order): string
    {
        return "Заявка №{$order->id}";
    }
}
