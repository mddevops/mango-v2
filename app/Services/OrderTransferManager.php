<?php

namespace App\Services;

use App\Enums\OrderOrigin;
use App\Enums\OrderStatusPurpose;
use App\Models\Client;
use App\Models\ContactChannel;
use App\Models\Order;
use App\Models\OrderPhone;
use App\Models\OrderStatus;
use App\Models\OrderTransfer;
use App\Models\Showroom;
use App\Models\Source;
use App\Models\SourceKind;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Transfers an order to another showroom by creating a new linked order there. The
 * source order keeps its showroom, source, responsible and history and gets the status
 * with the `transferred` purpose. The new order takes over only the client, the contact
 * name and phones, the channel and the car interest; it starts with the initial status,
 * no responsible, the receiver's business day and repeat number, and the internal source
 * «Другой салон · <sender>» of the receiving showroom. Comments, calls, history, dates,
 * reasons and results stay with the source order.
 *
 * Both showrooms are locked in id order (as distribution locks a showroom before an
 * order), then the source order. The operation id and the unique source order make a
 * repeated request or a second transfer of the same order harmless. The callback
 * distribution of the new order runs after the transfer is committed; its failure never
 * undoes the transfer.
 *
 * @phpstan-type TransferData array{showroom_id: int, comment: string|null, operation_id: string, lock_version: int}
 */
class OrderTransferManager
{
    public const KIND_CODE = 'other-showroom';

    public const SOURCE_PREFIX = 'Другой салон · ';

    public function __construct(
        private readonly AuditLogger $audit,
        private readonly AccessScope $scope,
        private readonly BusinessDayCalculator $businessDays,
        private readonly CallbackDistributor $distributor,
    ) {}

    /**
     * @param  TransferData  $data
     */
    public function transfer(Order $order, User $actor, array $data): OrderTransfer
    {
        $existing = $this->byOperation($data['operation_id'], $order);

        if ($existing !== null) {
            return $existing;
        }

        $target = Showroom::query()->find($data['showroom_id']);

        if ($target === null || ! $target->active || $target->id === $order->showroom_id || ! $this->scope->canAccessShowroom($actor, $target->id)) {
            throw ValidationException::withMessages(['showroom_id' => 'Выберите другой активный салон из вашей области доступа.']);
        }

        try {
            $transfer = DB::transaction(fn () => $this->transferLocked($order, $target, $actor, $data));
        } catch (UniqueConstraintViolationException $exception) {
            $transfer = $this->byOperation($data['operation_id'], $order) ?? throw $exception;
        }

        if ($transfer->wasRecentlyCreated) {
            $this->distribute($transfer, $actor);
        }

        return $transfer;
    }

    /**
     * The showrooms an order of this showroom may be transferred to by the employee.
     *
     * @return Builder<Showroom>
     */
    public function targets(Order $order, User $actor): Builder
    {
        return $this->scope->showrooms(Showroom::query(), $actor)
            ->where('showrooms.active', true)
            ->whereKeyNot($order->showroom_id);
    }

    /**
     * @param  TransferData  $data
     */
    private function transferLocked(Order $order, Showroom $target, User $actor, array $data): OrderTransfer
    {
        $showrooms = Showroom::query()->whereKey([$order->showroom_id, $target->id])->orderBy('id')->lockForUpdate()->get()->keyBy('id');

        $existing = $this->byOperation($data['operation_id'], $order);

        if ($existing !== null) {
            return $existing;
        }

        /** @var Order $locked */
        $locked = Order::query()->lockForUpdate()->findOrFail($order->id);
        /** @var Showroom $from */
        $from = $showrooms[$locked->showroom_id];
        /** @var Showroom $to */
        $to = $showrooms[$target->id];

        $done = OrderTransfer::query()->where('source_order_id', $locked->id)->with('toShowroom:id,name')->first();

        if ($done !== null) {
            throw ValidationException::withMessages(['showroom_id' => "Заявка уже передана в салон «{$done->toShowroom->name}»."]);
        }

        $locked->ensureLockVersion($data['lock_version']);

        if (! $to->active) {
            throw ValidationException::withMessages(['showroom_id' => 'Салон-получатель отключён.']);
        }

        $transferred = OrderStatus::query()->where('purpose', OrderStatusPurpose::Transferred)->where('active', true)->sharedLock()->first();
        $initial = OrderStatus::query()->where('is_initial', true)->where('active', true)->sharedLock()->first();

        if ($transferred === null || $initial === null) {
            throw ValidationException::withMessages(['showroom_id' => 'Не настроены статусы для передачи: нужен начальный статус и системный статус «Передано».']);
        }

        $source = $this->internalSource($from, $to, $locked->contact_channel_id);

        /** @var Client $client */
        $client = Client::query()->lockForUpdate()->findOrFail($locked->client_id);
        $repeat = Order::query()->where('client_id', $client->id)->where('showroom_id', $to->id)->count();
        $now = CarbonImmutable::now();
        $day = $this->businessDays->forShowroom($to, $now);

        $new = Order::create([
            'client_id' => $client->id,
            'showroom_id' => $to->id,
            'source_id' => $source->id,
            'contact_channel_id' => $locked->contact_channel_id,
            'origin' => OrderOrigin::Transfer,
            'contact_name' => $locked->contact_name,
            'phone' => $locked->phone,
            'order_status_id' => $initial->id,
            'responsible_id' => null,
            'car_interest' => $locked->car_interest,
            'event_at' => $now,
            'received_at' => $now,
            'business_date' => $day->date,
            'business_day_rule' => $day->rule->snapshot(),
            'repeat_number' => $repeat,
            'created_by' => $actor->id,
        ]);

        foreach ($locked->additionalPhones()->get() as $phone) {
            OrderPhone::create(['order_id' => $new->id, 'phone' => $phone->phone, 'name' => $phone->name, 'position' => $phone->position]);
        }

        $previousStatus = $locked->status()->value('name');
        $locked->order_status_id = $transferred->id;
        $locked->distribution_state = null;
        $locked->bumpLockVersion()->save();

        $transfer = OrderTransfer::create([
            'operation_id' => $data['operation_id'],
            'source_order_id' => $locked->id,
            'target_order_id' => $new->id,
            'from_showroom_id' => $from->id,
            'to_showroom_id' => $to->id,
            'user_id' => $actor->id,
            'user_name' => $actor->name,
            'comment' => $data['comment'],
        ]);

        $this->audit->record('order.transferred', 'order', $locked->id, "Заявка №{$locked->id}", "Передана в салон «{$to->name}»", [
            'status' => [$previousStatus, $transferred->name],
            'transferred_to' => $to->name,
        ], [$from->id]);

        $this->audit->record('order.created', 'order', $new->id, "Заявка №{$new->id}", "Получена из салона «{$from->name}»", [
            'origin' => OrderOrigin::Transfer->label(),
            'transferred_from' => $from->name,
            'showroom' => $to->name,
            'source' => $source->name,
            'channel' => ContactChannel::query()->whereKey($locked->contact_channel_id)->value('name'),
            'status' => $initial->name,
            'business_date' => $day->date,
            'repeat_number' => $repeat,
        ], [$to->id]);

        return $transfer;
    }

    /**
     * The receiving showroom's source for orders from the sending one, created on the
     * first transfer in that direction with the active channels of the kind. Every check
     * runs before the orders change; a disabled kind, source or channel is reported and
     * never enabled or linked here.
     */
    private function internalSource(Showroom $from, Showroom $to, int $channelId): Source
    {
        $kind = SourceKind::query()->where('code', self::KIND_CODE)->sharedLock()->first();

        if ($kind === null || ! $kind->active) {
            throw ValidationException::withMessages(['showroom_id' => 'Вид источника «Другой салон» отключён или не найден. Включите его в справочнике видов источников.']);
        }

        $channel = ContactChannel::query()->sharedLock()->find($channelId);
        $source = Source::query()
            ->where('showroom_id', $to->id)
            ->where('transfer_from_showroom_id', $from->id)
            ->lockForUpdate()
            ->first();

        if ($source !== null && ! $source->active) {
            throw ValidationException::withMessages(['showroom_id' => "Источник «{$source->name}» салона-получателя отключён. Включите его в разделе «Источники» или выберите другой салон."]);
        }

        $allowed = $source !== null
            ? $source->channels()->where('contact_channels.active', true)->whereKey($channelId)->exists()
            : $kind->channels()->where('contact_channels.active', true)->whereKey($channelId)->exists();

        if ($channel === null || ! $channel->active || ! $allowed) {
            $name = $channel->name ?? '—';

            throw ValidationException::withMessages(['showroom_id' => $source !== null
                ? "Тип заявки «{$name}» отключён или не разрешён источнику «{$source->name}» салона-получателя."
                : "Тип заявки «{$name}» отключён или не разрешён виду источника «Другой салон»."]);
        }

        if ($source === null) {
            $source = new Source([
                'showroom_id' => $to->id,
                'source_kind_id' => $kind->id,
                'name' => self::SOURCE_PREFIX.$from->name,
            ]);
            $source->transfer_from_showroom_id = $from->id;
            $source->code = "transfer-{$from->id}-{$to->id}";
            $source->save();
            $source->channels()->sync($kind->channels()->where('contact_channels.active', true)->pluck('contact_channels.id')->all());

            $this->audit->record('source.created', 'source', $source->id, $source->name, 'Создан источник для заявок, переданных из другого салона', [
                'code' => $source->code,
                'name' => $source->name,
                'showroom' => $to->name,
                'kind' => $kind->name,
                'transferred_from' => $from->name,
            ], [$to->id]);
        }

        return $source;
    }

    private function byOperation(string $operationId, Order $order): ?OrderTransfer
    {
        $transfer = OrderTransfer::query()->where('operation_id', $operationId)->first();

        if ($transfer !== null && $transfer->source_order_id !== $order->id) {
            throw ValidationException::withMessages(['operation_id' => 'Идентификатор операции уже использован. Обновите страницу.']);
        }

        return $transfer;
    }

    /**
     * A callback order of the receiver goes to its distribution; other channels stay
     * unassigned for a manual decision. Not finding anyone leaves the order waiting.
     */
    private function distribute(OrderTransfer $transfer, User $actor): void
    {
        $order = Order::query()->findOrFail($transfer->target_order_id);

        if ($order->contact_channel_id !== $this->distributor->callbackChannelId()) {
            return;
        }

        try {
            $this->distributor->request($order, $actor);
        } catch (Throwable $exception) {
            report($exception);
        }
    }
}
