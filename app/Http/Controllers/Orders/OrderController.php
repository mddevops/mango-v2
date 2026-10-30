<?php

namespace App\Http\Controllers\Orders;

use App\Http\Controllers\Controller;
use App\Http\Requests\Orders\OrderCommentRequest;
use App\Http\Requests\Orders\OrderRequest;
use App\Http\Requests\Orders\OrderTransferRequest;
use App\Models\AuditLog;
use App\Enums\CallRecordingState;
use App\Models\Call;
use App\Models\CallLeg;
use App\Models\CallRecording;
use App\Models\ContactChannel;
use App\Models\Order;
use App\Models\OrderComment;
use App\Models\OrderStatus;
use App\Models\OrderTransfer;
use App\Models\Showroom;
use App\Models\SiteCallbackRequest;
use App\Models\Source;
use App\Models\User;
use App\Models\UserPreference;
use App\Policies\OrderPolicy;
use App\Services\AccessScope;
use App\Services\CallbackDistributor;
use App\Services\OrderManager;
use App\Services\OrderTransferManager;
use App\Support\OrderReasonType;
use App\Support\OrdersListPreferences;
use App\Support\PermissionCatalog as P;
use App\Support\Phone;
use App\Support\TableFilter;
use App\Support\TableFilters;
use App\Support\TableQuery;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class OrderController extends Controller
{
    private const SECTION_PAGE_SIZE = 20;

    private const MAX_RELATED = 50;

    private const TAB_ALL = 'all';

    /** The latest comments a list card can page through; the rest are on the order page. */
    private const LIST_COMMENTS = 3;

    private const CHANGE_LABELS = [
        'origin' => 'Происхождение',
        'showroom' => 'Салон',
        'source' => 'Источник',
        'channel' => 'Тип заявки',
        'status' => 'Статус',
        'contact_name' => 'Имя',
        'phones' => 'Телефоны',
        'responsible' => 'Ответственный',
        'callback_at' => 'Перезвон',
        'arrival_at' => 'Приезд',
        'car_interest' => 'Интерес к автомобилю',
        'reason' => 'Причина',
        'reason_comment' => 'Пояснение к причине',
        'business_date' => 'Бизнес-дата',
        'repeat_number' => 'Повтор',
        'comment_id' => 'Комментарий',
        'transferred_to' => 'Передано в салон',
        'transferred_from' => 'Получено из салона',
        'recording' => 'Запись разговора',
    ];

    public function __construct(
        private readonly AccessScope $scope,
        private readonly OrderManager $orders,
        private readonly CallbackDistributor $distributor,
        private readonly OrderTransferManager $transfers,
    ) {}

    public function index(Request $request): Response
    {
        $this->authorize('viewAny', Order::class);

        /** @var User $user */
        $user = $request->user();

        $showrooms = $this->scope->showrooms(Showroom::query(), $user)->orderBy('name')->get(['id', 'name', 'active']);
        $sources = $this->scope->sources(Source::query(), $user)->orderBy('name')->get(['id', 'name', 'active']);
        $channels = ContactChannel::query()->orderBy('sort_order')->orderBy('name')->get(['id', 'name', 'active']);
        $statuses = OrderStatus::query()->orderBy('sort_order')->orderBy('name')->get(['id', 'name', 'active']);
        $responsibles = $this->scope->users(User::query(), $user)->orderBy('name')->get(['id', 'name', 'active']);

        $filters = TableFilters::fromRequest($request, [
            'search' => TableFilter::search(function (Builder $query, string $value): void {
                $phone = Phone::normalizeSearch($value);

                $phone === null
                    ? $query->where('orders.contact_name', 'like', '%'.addcslashes($value, '%_\\').'%')
                    : $query->where(fn (Builder $numbers) => $numbers
                        ->where('orders.phone', $phone)
                        ->orWhereExists(fn ($additional) => $additional->selectRaw('1')->from('order_phones')
                            ->whereColumn('order_phones.order_id', 'orders.id')
                            ->where('order_phones.phone', $phone)));
            }),
            'showroom' => TableFilter::column('orders.showroom_id', $showrooms->modelKeys()),
            'source' => TableFilter::column('orders.source_id', $sources->modelKeys()),
            'channel' => TableFilter::column('orders.contact_channel_id', $channels->modelKeys()),
            'order_status' => TableFilter::column('orders.order_status_id', $statuses->modelKeys()),
            'responsible' => TableFilter::column('orders.responsible_id', $responsibles->modelKeys(), nullable: true),
            'assignment' => TableFilter::choice([
                'unassigned' => fn (Builder $query) => $query->whereNull('orders.responsible_id'),
            ]),
            'business_date' => TableFilter::date('orders.business_date'),
            'received_at' => TableFilter::moment('orders.received_at', Showroom::DEFAULT_TIMEZONE),
        ]);

        $tabStatuses = OrderStatus::query()->where('show_as_tab', true)->where('active', true)
            ->orderBy('sort_order')->orderBy('name')->get(['id', 'name', 'color']);
        $tab = $this->listTab($request, $tabStatuses->modelKeys());
        $preferences = $this->listPreferences($user);
        [$sort, $direction] = TableQuery::sort($request, OrdersListPreferences::SORTS, $preferences['sort'] ?? 'received_at', $preferences['direction'] ?? 'desc');

        $filtered = fn (): Builder => $filters->apply($this->scope->orders(Order::query(), $user));

        $paginator = $this->sortList($this->applyTab($filtered(), $tab), $sort, $direction)
            ->with(['showroom:id,name,timezone', 'source:id,name,source_kind_id', 'source.kind:id,name,icon,show_icon,icon_color', 'channel:id,name,icon,show_icon,icon_color', 'status:id,name,color', 'responsible:id,name', 'transferOut.toShowroom:id,name', 'transferIn.fromShowroom:id,name'])
            ->with(['comments' => fn ($comments) => $comments
                ->select(['id', 'order_id', 'author_name', 'body', 'created_at'])
                ->orderByDesc('id')
                ->limit(self::LIST_COMMENTS)])
            ->withCount('comments')
            ->paginate(TableQuery::perPage($request))
            ->withQueryString();

        $callbackId = $this->distributor->callbackChannelId();

        return Inertia::render('orders/index', [
            'orders' => TableQuery::paginated($paginator, fn (Order $order) => [
                ...$this->row($order),
                'source_kind' => $order->source->kind->name,
                'source_icon' => $order->source->kind->show_icon ? $order->source->kind->icon : null,
                'source_icon_color' => $order->source->kind->icon_color->value,
                'channel_icon' => $order->channel->show_icon ? $order->channel->icon : null,
                'channel_icon_color' => $order->channel->icon_color->value,
                'showroom_id' => $order->showroom_id,
                'lock_version' => $order->lock_version,
                'can' => $this->actions($user, $order, $callbackId),
                'comments' => $order->comments->map(fn (OrderComment $comment) => [
                    'id' => $comment->id,
                    'author' => $comment->author_name,
                    'body' => $comment->body,
                    'created_at' => $comment->created_at?->toISOString(),
                ])->all(),
                'comments_count' => (int) $order->comments_count,
            ]),
            'transferShowrooms' => fn () => $user->can(P::ORDERS_TRANSFER)
                ? $this->options($this->scope->showrooms(Showroom::query(), $user)->where('active', true)->orderBy('name')->get(['id', 'name', 'active']))
                : [],
            'filters' => [
                'filter' => $filters->conditions,
                'per_page' => TableQuery::perPage($request),
                'sort' => $sort,
                'direction' => $direction,
                'tab' => $tab,
            ],
            'tabs' => fn () => $this->listTabs($filtered(), $tabStatuses),
            'listPreferences' => $preferences,
            'showrooms' => $this->options($showrooms),
            'sources' => $this->options($sources),
            'channels' => $this->options($channels),
            'statuses' => $this->options($statuses),
            'responsibles' => $this->options($responsibles),
            'createForm' => fn () => $user->can('create', Order::class) ? $this->createForm($user) : null,
            'can' => ['create' => $user->can('create', Order::class) && $this->creatableShowrooms($user)->exists()],
        ]);
    }

    /**
     * The list view saved for the employee's account, so the server renders the view the
     * client shows in any browser.
     *
     * @return array{view: 'table'|'cards', filters: bool, columns: array<string, bool>|null, order: list<string>|null, pinning: array{start: list<string>, end: list<string>}, sort: string|null, direction: 'asc'|'desc'|null}
     */
    private function listPreferences(User $user): array
    {
        return OrdersListPreferences::normalize(UserPreference::query()
            ->where('user_id', $user->id)
            ->where('key', UserPreference::ORDERS_LIST)
            ->value('value'));
    }

    /**
     * «all» or the id of a status shown as a tab; anything else is «all».
     *
     * @param  list<int>  $statusIds
     */
    private function listTab(Request $request, array $statusIds): string
    {
        $tab = $request->query('tab');

        if (is_string($tab) && ctype_digit($tab) && in_array((int) $tab, $statusIds, true)) {
            return $tab;
        }

        return self::TAB_ALL;
    }

    /**
     * @param  Builder<Order>  $query
     * @return Builder<Order>
     */
    private function applyTab(Builder $query, string $tab): Builder
    {
        return $tab === self::TAB_ALL ? $query : $query->where('orders.order_status_id', (int) $tab);
    }

    /**
     * Sorting by a related name uses a correlated subquery, so pagination and the access
     * scope stay on the orders table alone. The id keeps equal values in a stable order.
     *
     * @param  Builder<Order>  $query
     * @param  'asc'|'desc'  $direction
     * @return Builder<Order>
     */
    private function sortList(Builder $query, string $sort, string $direction): Builder
    {
        $related = fn (string $table, string $column, string $key) => DB::table($table)
            ->select("{$table}.{$column}")
            ->whereColumn("{$table}.id", "orders.{$key}")
            ->limit(1);

        match ($sort) {
            'showroom' => $query->orderBy($related('showrooms', 'name', 'showroom_id'), $direction),
            'source' => $query->orderBy($related('sources', 'name', 'source_id'), $direction),
            'channel' => $query->orderBy($related('contact_channels', 'name', 'contact_channel_id'), $direction),
            'status' => $query->orderBy($related('order_statuses', 'sort_order', 'order_status_id'), $direction)
                ->orderBy($related('order_statuses', 'name', 'order_status_id'), $direction),
            'responsible' => $query->orderBy($related('users', 'name', 'responsible_id'), $direction),
            default => $query->orderBy("orders.{$sort}", $direction),
        };

        return $query->orderBy('orders.id', $direction);
    }

    /**
     * Tab counters under the same scope and filters as the list, the tab itself aside.
     *
     * @param  Builder<Order>  $base
     * @param  Collection<int, OrderStatus>  $statuses
     * @return list<array{key: string, label: string, color: string|null, count: int}>
     */
    private function listTabs(Builder $base, Collection $statuses): array
    {
        $total = $base->clone()->toBase()->count();
        $byStatus = $statuses->isEmpty() ? collect() : $base->clone()->toBase()
            ->whereIn('orders.order_status_id', $statuses->modelKeys())
            ->groupBy('orders.order_status_id')
            ->selectRaw('orders.order_status_id as status_id, count(*) as aggregate')
            ->pluck('aggregate', 'status_id');

        return [
            ['key' => self::TAB_ALL, 'label' => 'Все', 'color' => null, 'count' => $total],
            ...$statuses->map(fn (OrderStatus $status) => [
                'key' => (string) $status->id,
                'label' => $status->name,
                'color' => $status->color->value,
                'count' => (int) ($byStatus[$status->id] ?? 0),
            ])->values()->all(),
        ];
    }

    /**
     * Active showrooms of the employee's scope in which orders can be created manually.
     *
     * @return Builder<Showroom>
     */
    private function creatableShowrooms(User $user): Builder
    {
        return $this->scope->showrooms(Showroom::query(), $user)->where('active', true);
    }

    /**
     * What depends on the chosen showroom: its sources and the reasons available in it.
     *
     * @return array{sources: list<array<string, mixed>>, reasons: array<string, mixed>}
     */
    private function showroomChoices(User $user, int $showroomId): array
    {
        $reasons = [];

        foreach (OrderReasonType::cases() as $type) {
            $reasons[$type->value] = $this->orders->availableReasons($type, $showroomId)->orderBy('sort_order')->orderBy('name')->get()
                ->map(fn ($reason) => [...$this->option($reason), 'comment_required' => $reason->comment_required])
                ->values()
                ->all();
        }

        return ['sources' => $this->creatableSources($user, $showroomId), 'reasons' => $reasons];
    }

    /**
     * Regular active sources of one showroom with their active channels. Internal sources of
     * transfers between showrooms are recognised by their link to the sending showroom.
     *
     * @return list<array<string, mixed>>
     */
    private function creatableSources(User $user, int $showroomId): array
    {
        return $this->scope->sources(Source::query(), $user)
            ->where('showroom_id', $showroomId)
            ->where('active', true)
            ->whereNull('transfer_from_showroom_id')
            ->with(['channels' => fn ($query) => $query->where('contact_channels.active', true)->orderBy('sort_order')->orderBy('name')])
            ->orderBy('name')
            ->get()
            ->map(fn (Source $source) => [
                'id' => $source->id,
                'name' => $source->name,
                'channels' => $source->channels->map(fn (ContactChannel $channel) => ['id' => $channel->id, 'name' => $channel->name])->values()->all(),
            ])
            ->values()
            ->all();
    }

    /**
     * What the creation dialog may offer. The showroom is chosen first; with a single
     * showroom its sources are sent at once, otherwise they are loaded after the choice.
     *
     * @return array<string, mixed>|null
     */
    private function createForm(User $user): ?array
    {
        $showrooms = $this->creatableShowrooms($user)->orderBy('name')->get(['id', 'name']);

        if ($showrooms->isEmpty()) {
            return null;
        }

        $assignAny = $user->can(P::ORDERS_ASSIGN_ANY);
        $responsibles = null;

        if (OrderPolicy::canAssign($user)) {
            $showroomIds = $showrooms->modelKeys();
            $responsibles = $this->orders->responsibleCandidates(null, true)
                ->when(! $assignAny, fn (Builder $query) => $query->where(fn (Builder $inner) => $inner
                    ->where('has_global_access', true)
                    ->orWhereHas('showrooms', fn (Builder $showrooms) => $showrooms->whereIn('showrooms.id', $showroomIds))))
                ->with('showrooms:id')
                ->orderBy('name')
                ->get(['users.id', 'users.name', 'users.has_global_access'])
                ->map(fn (User $candidate) => [
                    'id' => $candidate->id,
                    'name' => $candidate->name,
                    'showroom_ids' => $assignAny || $candidate->has_global_access ? null : array_values(array_intersect($candidate->showrooms->modelKeys(), $showroomIds)),
                ])
                ->values();
        }

        $statuses = OrderStatus::query()->where('active', true)->whereNull('purpose')->orderBy('sort_order')->orderBy('name')->get();

        return [
            'showrooms' => $this->options($showrooms),
            'choices' => $showrooms->count() === 1 ? $this->showroomChoices($user, $showrooms->first()->id) : null,
            'statuses' => $statuses->map(fn (OrderStatus $status) => [
                'id' => $status->id,
                'name' => $status->name,
                'active' => $status->active,
                'color' => $status->color->value,
                'requires_callback_at' => $status->requires_callback_at,
                'requires_arrival_at' => $status->requires_arrival_at,
                'required_reason' => $status->required_reason?->value,
            ])->values(),
            'initialStatusId' => $statuses->firstWhere('is_initial', true)?->id,
            'reasonTitles' => collect(OrderReasonType::cases())->mapWithKeys(fn (OrderReasonType $type) => [$type->value => $type->singular()]),
            'responsibles' => $responsibles,
            'selfId' => $user->id,
            'maxExtraPhones' => OrderRequest::MAX_EXTRA_PHONES,
            'canComment' => $user->can(P::ORDERS_COMMENT),
        ];
    }

    /**
     * Sources of the showroom chosen in the creation dialog; a showroom outside the
     * employee's scope answers 404.
     */
    public function createSources(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $this->authorize('create', Order::class);

        $showroom = $this->creatableShowrooms($user)->findOrFail($request->integer('showroom_id'));

        return response()->json($this->showroomChoices($user, $showroom->id));
    }

    /**
     * The client's earlier orders in the same showroom, for the repeat counter of the list.
     */
    public function previous(Request $request, int $order): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $model = $this->scope->orders(Order::query(), $user)->findOrFail($order);

        $this->authorize('viewPrevious', $model);

        return response()->json($this->related($this->previousOrders($model, $user), $user));
    }

    public function store(OrderRequest $request): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();

        $order = $this->orders->create($user, $request->createData());

        return $this->afterSave($user, $order, "Заявка №{$order->id} создана.", to_route('orders.show', $order->id));
    }

    /**
     * Opening the card is recorded as a view after the access check. Prefetching and
     * partial reloads (comments, history, the form's own reload after saving) are not.
     * None of this touches the order, so `updated_at` stays the time of the last change.
     */
    /**
     * Whether the current user may still open the order. Asked by an open card after a
     * realtime «check access» signal or a reconnect; an unknown order is simply not
     * visible.
     */
    public function access(Request $request, int $order): JsonResponse
    {
        $model = Order::query()->find($order);

        return response()->json(['visible' => $model !== null && $request->user()->can('view', $model)]);
    }

    public function show(Request $request, int $order): Response
    {
        /** @var User $user */
        $user = $request->user();

        $model = $this->scope->orders(Order::query(), $user)
            ->with(['showroom', 'source:id,name,active', 'channel:id,name,active', 'status', 'responsible:id,name,active', 'creator:id,name', 'trashReason', 'dropReason', 'additionalPhones', 'transferOut.toShowroom:id,name', 'transferOut.targetOrder', 'transferIn.fromShowroom:id,name', 'transferIn.sourceOrder', 'siteRequest'])
            ->findOrFail($order);

        $this->authorize('view', $model);

        if (! $request->prefetch() && ! $request->hasHeader('X-Inertia-Partial-Component')) {
            $this->orders->recordView($model);
        }

        $actions = $this->actions($user, $model, $this->distributor->callbackChannelId());

        $can = [
            'update' => $user->can('update', $model),
            'assign' => $user->can('assign', $model),
            'comment' => $user->can('comment', $model),
            'history' => $user->can('viewHistory', $model),
            'recordings' => $user->can('listenRecordings', $model),
            'previous' => $user->can('viewPrevious', $model),
            'crossShowroom' => $user->can('viewCrossShowroom', $model),
            'distribute' => $actions['distribute'],
            'take' => $actions['take'],
            'transfer' => $actions['transfer'],
        ];

        $props = [
            'order' => fn () => $this->card($model, $user),
            'transferShowrooms' => fn () => $can['transfer'] ? $this->options($this->transfers->targets($model, $user)->orderBy('name')->get(['id', 'name', 'active'])) : [],
            'options' => fn () => $can['update'] ? $this->editOptions($model, $can['assign'], $user->can(P::ORDERS_ASSIGN_ANY)) : null,
            'comments' => Inertia::optional(fn () => $this->comments($request, $model)),
            'can' => $can,
        ];

        if ($can['previous']) {
            $props['previous'] = fn () => $this->related($this->previousOrders($model, $user), $user);
        }

        if ($can['crossShowroom']) {
            $props['crossShowroom'] = Inertia::optional(fn () => $this->related($this->crossShowroomOrders($model, $user), $user));
        }

        if ($can['history']) {
            $props['history'] = Inertia::optional(fn () => $this->history($request, $model));
            $props['calls'] = Inertia::optional(fn () => $this->calls($model, $can['recordings']));
        }

        return Inertia::render('orders/show', $props);
    }

    public function update(OrderRequest $request): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();

        $order = $this->orders->update($request->order(), $request->updateData());

        return $this->afterSave($user, $order, "Заявка №{$order->id} сохранена.", back());
    }

    public function take(Request $request, int $order): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();
        $model = $this->scope->orders(Order::query(), $user)->findOrFail($order);

        $this->authorize('update', $model);

        $this->orders->take($model, $user);

        TableQuery::flashSuccess("Заявка №{$model->id} взята в работу.");

        return back();
    }

    public function transfer(OrderTransferRequest $request): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();
        $source = $request->order();

        $transfer = $this->transfers->transfer($source, $user, $request->transferData());
        $target = $transfer->targetOrder()->with('responsible:id,name')->firstOrFail();
        $showroom = $transfer->toShowroom()->value('name');

        $assigned = $target->responsible !== null ? " Ответственный: {$target->responsible->name}." : ' Ответственный не назначен.';
        $number = $user->can('view', $target) ? " Новая заявка №{$target->id}." : '';

        TableQuery::flashSuccess("Заявка №{$source->id} передана в салон «{$showroom}».{$number}{$assigned}");

        return back();
    }

    /**
     * Assigning the order to someone else may take it out of the employee's scope; then
     * the card cannot be shown and the employee returns to the list.
     */
    private function afterSave(User $user, Order $order, string $message, RedirectResponse $redirect): RedirectResponse
    {
        if ($user->can('view', $order->fresh())) {
            TableQuery::flashSuccess($message);

            return $redirect;
        }

        TableQuery::flashSuccess("{$message} Заявка передана другому ответственному и больше вам не видна.");

        return to_route('orders.index');
    }

    public function storeComment(OrderCommentRequest $request): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();

        $this->orders->addComment($request->order(), $user, $request->body());

        TableQuery::flashSuccess('Комментарий добавлен.');

        return back();
    }

    /**
     * @return array<string, mixed>
     */
    private function row(Order $order): array
    {
        return [
            'id' => $order->id,
            'received_at' => $order->received_at->toISOString(),
            'business_date' => $order->business_date,
            'contact_name' => $order->contact_name,
            'phone' => Phone::format($order->phone),
            'showroom' => $order->showroom->name,
            'source' => $order->source->name,
            'channel' => $order->channel->name,
            'status' => ['name' => $order->status->name, 'color' => $order->status->color->value],
            'responsible' => $order->responsible?->name,
            'repeat_number' => $order->repeat_number,
            'callback_at' => $this->orders->formatLocal($order->callback_at, $order->showroom->timezone),
            'arrival_at' => $this->orders->formatLocal($order->arrival_at, $order->showroom->timezone),
            'updated_at' => $order->updated_at?->toISOString(),
            'transferred_to' => $order->transferOut?->toShowroom->name,
            'transferred_from' => $order->transferIn?->fromShowroom->name,
        ];
    }

    /**
     * Row actions of the list; each one is checked again on the server when used.
     *
     * @return array<string, bool>
     */
    private function actions(User $user, Order $order, ?int $callbackId): array
    {
        $unassigned = $order->responsible_id === null;

        return [
            'take' => $unassigned && $user->can('take', $order),
            'assign' => $unassigned && $user->can('assign', $order),
            'distribute' => $unassigned && $order->contact_channel_id === $callbackId && $user->can('distribute', $order),
            'transfer' => $order->transferOut === null && $user->can('transfer', $order),
            'previous' => $order->repeat_number > 0 && $user->can('viewPrevious', $order),
        ];
    }

    /**
     * The showroom name of a linked order is shown to everyone who sees this one; its
     * number and link only to those who may open it.
     *
     * @return array<string, mixed>|null
     */
    private function transferInfo(?OrderTransfer $transfer, ?Order $linked, string $showroom, User $user): ?array
    {
        if ($transfer === null) {
            return null;
        }

        $visible = $linked !== null && $user->can('view', $linked);

        return [
            'showroom' => $showroom,
            'order_id' => $visible ? $linked->id : null,
            'user' => $transfer->user_name,
            'comment' => $transfer->comment,
            'created_at' => $transfer->created_at?->toISOString(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function card(Order $order, User $user): array
    {
        $timezone = $order->showroom->timezone;
        $reason = $order->reason();

        return [
            'id' => $order->id,
            'origin' => $order->origin->label(),
            'showroom' => ['id' => $order->showroom_id, 'name' => $order->showroom->name, 'timezone' => $timezone],
            'source' => $this->option($order->source),
            'channel' => $this->option($order->channel),
            'status' => [
                'id' => $order->status->id,
                'name' => $order->status->name,
                'color' => $order->status->color->value,
            ],
            'responsible' => $order->responsible ? $this->option($order->responsible) : null,
            'creator' => $order->creator?->name,
            'contact_name' => $order->contact_name,
            'phones' => array_map(fn (array $phone) => ['value' => $phone['phone'], 'formatted' => Phone::format($phone['phone']), 'name' => $phone['name']], $order->contactPhones()),
            'car_interest' => $order->car_interest,
            'callback_at' => $this->orders->formatLocal($order->callback_at, $timezone),
            'arrival_at' => $this->orders->formatLocal($order->arrival_at, $timezone),
            'reason' => $reason ? ['type' => $order->reasonType()?->value, ...$this->option($reason)] : null,
            'reason_comment' => $order->reason_comment,
            'event_at' => $order->event_at->toISOString(),
            'received_at' => $order->received_at->toISOString(),
            'business_date' => $order->business_date,
            'business_day_rule' => $order->business_day_rule,
            'repeat_number' => $order->repeat_number,
            'distribution' => $order->distribution_state === null ? null : [
                'state' => $order->distribution_state->value,
                'label' => $order->distribution_state->label(),
            ],
            'transfer_out' => $this->transferInfo($order->transferOut, $order->transferOut?->targetOrder, (string) $order->transferOut?->toShowroom->name, $user),
            'transfer_in' => $this->transferInfo($order->transferIn, $order->transferIn?->sourceOrder, (string) $order->transferIn?->fromShowroom->name, $user),
            'site_request' => $this->siteRequestInfo($order->siteRequest),
            'created_at' => $order->created_at?->toISOString(),
            'updated_at' => $order->updated_at?->toISOString(),
            'lock_version' => $order->lock_version,
        ];
    }

    /**
     * What the site actually sent: entry page, form, advertising tags and viewed vehicles
     * by section. The visitor IP and the request's technical data are not shown.
     *
     * @return array<string, mixed>|null
     */
    private function siteRequestInfo(?SiteCallbackRequest $request): ?array
    {
        if ($request === null) {
            return null;
        }

        $vehicles = [];

        foreach (SiteCallbackRequest::VEHICLE_SECTIONS as $section => $label) {
            $ids = $request->viewed_vehicles[$section] ?? [];

            if ($ids !== []) {
                $vehicles[] = ['section' => $section, 'label' => $label, 'ids' => array_values($ids)];
            }
        }

        return [
            'url' => $request->url,
            'form' => $request->form,
            'utm' => $request->utm(),
            'viewed_vehicles' => $vehicles,
        ];
    }

    /**
     * The values a form may offer. A disabled channel, status, reason or employee that the
     * order already has stays visible as the current value but is not offered otherwise.
     *
     * @return array<string, mixed>
     */
    private function editOptions(Order $order, bool $canAssign, bool $assignAny): array
    {
        $channels = ContactChannel::query()
            ->whereHas('sources', fn (Builder $query) => $query->where('sources.id', $order->source_id))
            ->where(fn (Builder $query) => $query->where('active', true)->orWhereKey($order->contact_channel_id))
            ->orderBy('sort_order')->orderBy('name')
            ->get();

        $statuses = OrderStatus::query()
            ->where(fn (Builder $query) => $query->where(fn (Builder $offered) => $offered->where('active', true)->whereNull('purpose'))->orWhereKey($order->order_status_id))
            ->orderBy('sort_order')->orderBy('name')
            ->get();

        $reasons = [];

        foreach (OrderReasonType::cases() as $type) {
            $current = $order->reasonType() === $type ? $order->reasonId() : null;
            $available = $this->orders->availableReasons($type, $order->showroom_id)->orderBy('sort_order')->orderBy('name')->get();

            if ($current !== null && ! $available->contains('id', $current)) {
                $available->prepend($order->reason());
            }

            $reasons[$type->value] = $available->map(fn ($reason) => [
                ...$this->option($reason),
                'comment_required' => $reason->comment_required,
            ])->values();
        }

        $responsibles = null;

        if ($canAssign) {
            $candidates = $this->orders->responsibleCandidates($order->showroom_id, $assignAny)->orderBy('name')->get(['id', 'name', 'active']);

            if ($order->responsible !== null && ! $candidates->contains('id', $order->responsible_id)) {
                $candidates->prepend($order->responsible);
            }

            $responsibles = $this->options($candidates);
        }

        return [
            'channels' => $this->options($channels),
            'statuses' => $statuses->map(fn (OrderStatus $status) => [
                'id' => $status->id,
                'name' => $status->name,
                'active' => $status->active,
                'color' => $status->color->value,
                'requires_callback_at' => $status->requires_callback_at,
                'requires_arrival_at' => $status->requires_arrival_at,
                'required_reason' => $status->required_reason?->value,
            ])->values(),
            'reasons' => $reasons,
            'reasonTitles' => collect(OrderReasonType::cases())->mapWithKeys(fn (OrderReasonType $type) => [$type->value => $type->singular()]),
            'responsibles' => $responsibles,
            'maxExtraPhones' => OrderRequest::MAX_EXTRA_PHONES,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function comments(Request $request, Order $order): array
    {
        $paginator = OrderComment::query()
            ->where('order_id', $order->id)
            ->orderByDesc('id')
            ->paginate(self::SECTION_PAGE_SIZE, ['*'], 'comments_page');

        return TableQuery::paginated($paginator, fn (OrderComment $comment) => [
            'id' => $comment->id,
            'author' => $comment->author_name,
            'body' => $comment->body,
            'created_at' => $comment->created_at?->toISOString(),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function history(Request $request, Order $order): array
    {
        $paginator = AuditLog::query()
            ->where('subject_type', 'order')
            ->where('subject_id', $order->id)
            ->orderByDesc('id')
            ->paginate(self::SECTION_PAGE_SIZE, ['*'], 'history_page');

        return TableQuery::paginated($paginator, fn (AuditLog $log) => [
            'id' => $log->id,
            'created_at' => $log->created_at->toISOString(),
            'actor' => $log->actor_name,
            'system' => $log->actor_kind === AuditLog::ACTOR_SYSTEM,
            'event' => $log->event,
            'description' => $log->description,
            'changes' => collect($log->changes ?? [])->map(fn (mixed $value, string $field) => [
                'field' => self::CHANGE_LABELS[$field] ?? $field,
                'before' => is_array($value) ? $this->scalar($value[0] ?? null) : null,
                'after' => $this->scalar(is_array($value) ? ($value[1] ?? null) : $value),
                'diff' => is_array($value),
            ])->values(),
        ]);
    }

    /**
     * Calls of the order with Mango's own times. Duration is shown only from the summary;
     * nothing is derived from the moments the CRM received events. Mango ids, raw numbers
     * and the matching diagnostics stay on the connection page of the administrator.
     * Recordings are added only for `orders.recordings`: their state and the address of
     * the protected player route, never a Mango link, a recording id or an error detail.
     *
     * @return array<string, mixed>
     */
    private function calls(Order $order, bool $recordings): array
    {
        $paginator = Call::query()
            ->where('order_id', $order->id)
            ->with(['legs.answeredBy:id,name', 'answeredBy:id,name', 'source:id,name', 'line:id,type,value'])
            ->when($recordings, fn (Builder $query) => $query->with('recordings:id,call_id,state,received_at'))
            ->orderByDesc('started_at')
            ->orderByDesc('id')
            ->paginate(self::SECTION_PAGE_SIZE, ['*'], 'calls_page');

        return TableQuery::paginated($paginator, function (Call $call) use ($order, $recordings) {
            $finished = $call->summary_received_at !== null;
            $legsDone = $call->legs->isNotEmpty() && $call->legs->every(fn (CallLeg $leg) => $leg->state === 'Disconnected');

            return [
                'id' => $call->id,
                'direction' => $call->technical ? 'Технический (команда Mango)' : ($call->direction?->label() ?? 'Не определено'),
                'started_at' => $call->started_at?->toISOString(),
                'ended_at' => $call->ended_at?->toISOString() ?? ($legsDone ? $call->legs->max('ended_at')?->toISOString() : null),
                'state' => match (true) {
                    $finished => 'Завершён',
                    $legsDone => 'Завершён, итог от Mango не получен',
                    default => 'Идёт или итог ещё не получен',
                },
                'missed' => $call->result === Call::MISSED,
                'talk_seconds' => $call->talk_seconds,
                'answered' => $call->answeredBy?->name ?? ($call->answered_extension === null ? null : "внутр. {$call->answered_extension} — сотрудник не сопоставлен"),
                'source' => $call->source?->name,
                'line' => $call->line?->value ?? $call->line_number,
                'legs' => $call->legs->count() < 2 ? [] : $call->legs->map(fn (CallLeg $leg) => [
                    'id' => $leg->id,
                    'employee' => $this->legParty($leg),
                    'transferred' => $leg->taken_from_call_id !== null,
                    'connected_at' => $leg->connected_at?->toISOString(),
                    'ended_at' => $leg->ended_at?->toISOString(),
                ])->values(),
                ...($recordings ? ['recordings' => $call->recordings->values()->map(fn (CallRecording $recording, int $index) => [
                    'id' => $recording->id,
                    'label' => 'Запись '.($index + 1),
                    'state' => $recording->state->value,
                    'state_label' => $recording->state->label(),
                    'audio_url' => $recording->state === CallRecordingState::Recorded ? route('orders.recordings.audio', [$order->id, $recording->id], false) : null,
                    'play_url' => $recording->state === CallRecordingState::Recorded ? route('orders.recordings.play', [$order->id, $recording->id], false) : null,
                ])->all()] : []),
            ];
        });
    }

    private function legParty(CallLeg $leg): ?string
    {
        if ($leg->answered_extension !== null) {
            return $leg->answeredBy->name ?? "внутр. {$leg->answered_extension}";
        }

        return $leg->to_extension === null ? null : "внутр. {$leg->to_extension}, без ответа";
    }

    /**
     * Only the earlier orders the employee may see; the repeat counter on the card keeps
     * counting all of them without revealing the others.
     *
     * @return Builder<Order>
     */
    private function previousOrders(Order $order, User $user): Builder
    {
        return $this->scope->orders(Order::query(), $user)
            ->where('client_id', $order->client_id)
            ->where('showroom_id', $order->showroom_id)
            ->where('id', '<', $order->id);
    }

    /**
     * Orders of the same client in other showrooms the employee may see. The scope is
     * applied in the query, so foreign showrooms are never loaded.
     *
     * @return Builder<Order>
     */
    private function crossShowroomOrders(Order $order, User $user): Builder
    {
        return $this->scope->orders(Order::query(), $user)
            ->where('client_id', $order->client_id)
            ->where('showroom_id', '!=', $order->showroom_id);
    }

    /**
     * @param  Builder<Order>  $query
     * @return list<array<string, mixed>>
     */
    private function related(Builder $query, User $user): array
    {
        return array_values($query
            ->with(['showroom:id,name', 'source:id,name', 'channel:id,name', 'status:id,name,color', 'responsible:id,name'])
            ->orderByDesc('received_at')
            ->orderByDesc('id')
            ->limit(self::MAX_RELATED)
            ->get()
            ->map(fn (Order $related) => [
                'id' => $related->id,
                'received_at' => $related->received_at->toISOString(),
                'business_date' => $related->business_date,
                'showroom' => $related->showroom->name,
                'source' => $related->source->name,
                'channel' => $related->channel->name,
                'status' => ['name' => $related->status->name, 'color' => $related->status->color->value],
                'responsible' => $related->responsible?->name,
                'can_open' => $user->can('view', $related),
            ])
            ->all());
    }

    private function scalar(mixed $value): ?string
    {
        return match (true) {
            $value === null => null,
            is_bool($value) => $value ? 'да' : 'нет',
            is_scalar($value) => (string) $value,
            default => json_encode($value, JSON_UNESCAPED_UNICODE) ?: null,
        };
    }

    /**
     * @return array{id: int, name: string, active: bool}
     */
    private function option(Model $model): array
    {
        return ['id' => (int) $model->getKey(), 'name' => (string) $model->getAttribute('name'), 'active' => (bool) ($model->getAttribute('active') ?? true)];
    }

    /**
     * @param  iterable<Model>  $models
     * @return list<array{id: int, name: string, active: bool}>
     */
    private function options(iterable $models): array
    {
        $options = [];

        foreach ($models as $model) {
            $options[] = $this->option($model);
        }

        return $options;
    }
}
