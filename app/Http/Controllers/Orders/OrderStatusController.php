<?php

namespace App\Http\Controllers\Orders;

use App\Enums\OrderStatusColor;
use App\Http\Controllers\Controller;
use App\Http\Requests\Orders\OrderStatusRequest;
use App\Http\Requests\Organization\StatusRequest;
use App\Http\Requests\ReorderRequest;
use App\Models\OrderStatus;
use App\Services\OrderStatusManager;
use App\Services\ReferenceOrder;
use App\Support\OrderReasonType;
use App\Support\TableFilter;
use App\Support\TableFilters;
use App\Support\TableQuery;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class OrderStatusController extends Controller
{
    public function __construct(private readonly OrderStatusManager $statuses) {}

    public function index(Request $request): Response
    {
        Gate::authorize('order-references.view');

        $filters = TableFilters::fromRequest($request, [
            'search' => TableFilter::text(['name', 'code']),
            'status' => TableFilter::status(),
        ]);

        $paginator = $filters->apply(OrderStatus::query())
            ->orderBy('sort_order')
            ->orderBy('id')
            ->paginate(TableQuery::perPage($request))
            ->withQueryString();

        return Inertia::render('order-references/statuses', [
            'items' => TableQuery::paginated($paginator, fn (OrderStatus $status) => [
                'id' => $status->id,
                'code' => $status->code,
                'name' => $status->name,
                'description' => $status->description,
                'color' => $status->color->value,
                'active' => $status->active,
                'is_initial' => $status->is_initial,
                'purpose' => $status->purpose?->label(),
                'show_as_tab' => $status->show_as_tab,
                'requires_callback_at' => $status->requires_callback_at,
                'requires_arrival_at' => $status->requires_arrival_at,
                'requires_visit_result' => $status->requires_visit_result,
                'required_reason' => $status->required_reason?->value,
                'lock_version' => $status->lock_version,
            ]),
            'filters' => [
                'filter' => $filters->conditions,
                'per_page' => TableQuery::perPage($request),
            ],
            'initial' => OrderStatus::query()->where('is_initial', true)->value('name'),
            'colors' => OrderStatusColor::options(),
            'reasonTypes' => OrderReasonType::options(),
            'can' => ['manage' => Gate::allows('order-references.manage')],
        ]);
    }

    public function store(OrderStatusRequest $request): RedirectResponse
    {
        $status = $this->statuses->create($request->createData());

        TableQuery::flashSuccess("Статус «{$status->name}» создан.");

        return back();
    }

    public function update(OrderStatusRequest $request, int $id): RedirectResponse
    {
        $status = $this->statuses->update($id, $request->updateData());

        TableQuery::flashSuccess("Статус «{$status->name}» сохранён.");

        return back();
    }

    public function status(StatusRequest $request, int $id): RedirectResponse
    {
        $status = $this->statuses->setActive($id, $request->boolean('active'), $request->validated('lock_version'));

        TableQuery::flashSuccess("Статус «{$status->name}» ".($status->active ? 'включён.' : 'отключён.'));

        return back();
    }

    public function reorder(ReorderRequest $request, ReferenceOrder $order): RedirectResponse
    {
        $order->reorder(OrderStatus::class, $request->ids(), 'order_status', 'Статусы заявок');

        return back();
    }

    public function initial(Request $request, int $id): RedirectResponse
    {
        Gate::authorize('order-references.manage');

        $validated = $request->validate(['lock_version' => ['required', 'integer', 'min:0']]);
        $status = $this->statuses->makeInitial($id, $validated['lock_version']);

        TableQuery::flashSuccess("Начальный статус новых заявок — «{$status->name}».");

        return back();
    }
}
