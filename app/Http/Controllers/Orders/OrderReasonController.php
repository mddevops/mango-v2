<?php

namespace App\Http\Controllers\Orders;

use App\Http\Controllers\Controller;
use App\Http\Requests\Orders\OrderReasonRequest;
use App\Http\Requests\Organization\StatusRequest;
use App\Http\Requests\ReorderRequest;
use App\Models\OrderDropReason;
use App\Models\OrderTrashReason;
use App\Models\Showroom;
use App\Models\User;
use App\Services\AccessScope;
use App\Services\OrderReasonManager;
use App\Services\ReferenceOrder;
use App\Support\OrderReasonType;
use App\Support\TableFilter;
use App\Support\TableFilters;
use App\Support\TableQuery;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class OrderReasonController extends Controller
{
    public function __construct(
        private readonly OrderReasonManager $reasons,
        private readonly AccessScope $scope,
    ) {}

    /**
     * A viewer outside global access sees reasons for every showroom and those linked to
     * at least one accessible showroom; foreign showrooms are never listed.
     */
    public function index(Request $request, OrderReasonType $type): Response
    {
        Gate::authorize('order-references.view');

        /** @var User $user */
        $user = $request->user();
        $ids = $this->scope->showroomIds($user);

        $filters = TableFilters::fromRequest($request, [
            'search' => TableFilter::text(['name', 'code']),
            'status' => TableFilter::status(),
        ]);

        $paginator = $filters->apply($type->model()::query())
            ->when($ids !== null, fn (Builder $query) => $query->where(fn (Builder $scoped) => $scoped
                ->where('all_showrooms', true)
                ->orWhereHas('showrooms', fn (Builder $showrooms) => $showrooms->whereIn('showrooms.id', $ids))))
            ->with(['showrooms' => fn ($showrooms) => $showrooms
                ->when($ids !== null, fn ($visible) => $visible->whereIn('showrooms.id', $ids))
                ->orderBy('name')
                ->select(['showrooms.id', 'showrooms.name', 'showrooms.active'])])
            ->orderBy('sort_order')
            ->orderBy('id')
            ->paginate(TableQuery::perPage($request))
            ->withQueryString();

        return Inertia::render('order-references/reasons', [
            'type' => $type->value,
            'title' => $type->title(),
            'singular' => $type->singular(),
            'items' => TableQuery::paginated($paginator, fn (OrderTrashReason|OrderDropReason $reason) => [
                'id' => $reason->id,
                'code' => $reason->code,
                'name' => $reason->name,
                'description' => $reason->description,
                'active' => $reason->active,
                'comment_required' => $reason->comment_required,
                'all_showrooms' => $reason->all_showrooms,
                'showrooms' => $reason->showrooms->map(fn (Showroom $showroom) => [
                    'id' => $showroom->id,
                    'name' => $showroom->name,
                    'active' => $showroom->active,
                ])->values(),
                'lock_version' => $reason->lock_version,
            ]),
            'filters' => [
                'filter' => $filters->conditions,
                'per_page' => TableQuery::perPage($request),
            ],
            'showrooms' => $this->scope->showrooms(Showroom::query(), $user)->orderBy('name')->get(['id', 'name', 'active']),
            'can' => ['manage' => Gate::allows('order-references.manage')],
        ]);
    }

    public function store(OrderReasonRequest $request, OrderReasonType $type): RedirectResponse
    {
        $reason = $this->reasons->create($type, $request->createData());

        TableQuery::flashSuccess("{$type->singular()} «{$reason->name}» создана.");

        return back();
    }

    public function update(OrderReasonRequest $request, OrderReasonType $type, int $id): RedirectResponse
    {
        $reason = $this->reasons->update($type, $id, $request->updateData());

        TableQuery::flashSuccess("{$type->singular()} «{$reason->name}» сохранена.");

        return back();
    }

    public function reorder(ReorderRequest $request, OrderReasonType $type, ReferenceOrder $order): RedirectResponse
    {
        $order->reorder($type->model(), $request->ids(), $type->auditSubject(), $type->title());

        return back();
    }

    public function status(StatusRequest $request, OrderReasonType $type, int $id): RedirectResponse
    {
        $reason = $this->reasons->setActive($type, $id, $request->boolean('active'), $request->validated('lock_version'));

        TableQuery::flashSuccess("{$type->singular()} «{$reason->name}» ".($reason->active ? 'включена.' : 'отключена.'));

        return back();
    }
}
