<?php

namespace App\Http\Controllers\Orders;

use App\Http\Controllers\Controller;
use App\Http\Requests\Orders\VisitResultRequest;
use App\Http\Requests\Organization\StatusRequest;
use App\Http\Requests\ReorderRequest;
use App\Models\User;
use App\Models\VisitResult;
use App\Services\AccessScope;
use App\Services\ReferenceOrder;
use App\Services\VisitResultManager;
use App\Support\TableFilter;
use App\Support\TableFilters;
use App\Support\TableQuery;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class VisitResultController extends Controller
{
    public function __construct(
        private readonly VisitResultManager $results,
        private readonly AccessScope $scope,
    ) {}

    public function index(Request $request): Response
    {
        Gate::authorize('order-references.view');

        /** @var User $user */
        $user = $request->user();

        $filters = TableFilters::fromRequest($request, [
            'search' => TableFilter::text(['name', 'code']),
            'status' => TableFilter::status(),
        ]);

        $paginator = $filters->apply(VisitResult::query())
            ->withCount(['orders' => fn (Builder $orders) => $this->scope->orders($orders, $user)])
            ->orderBy('sort_order')
            ->orderBy('id')
            ->paginate(TableQuery::perPage($request))
            ->withQueryString();

        return Inertia::render('order-references/visit-results', [
            'items' => TableQuery::paginated($paginator, fn (VisitResult $result) => [
                'id' => $result->id,
                'code' => $result->code,
                'name' => $result->name,
                'active' => $result->active,
                'orders_count' => (int) $result->getAttribute('orders_count'),
                'lock_version' => $result->lock_version,
            ]),
            'filters' => [
                'filter' => $filters->conditions,
                'per_page' => TableQuery::perPage($request),
            ],
            'can' => ['manage' => Gate::allows('order-references.manage')],
        ]);
    }

    public function store(VisitResultRequest $request): RedirectResponse
    {
        $result = $this->results->create($request->name());

        TableQuery::flashSuccess(VisitResult::SINGULAR." «{$result->name}» создан.");

        return back();
    }

    public function update(VisitResultRequest $request, int $id): RedirectResponse
    {
        $result = $this->results->update($id, $request->name(), $request->lockVersion());

        TableQuery::flashSuccess(VisitResult::SINGULAR." «{$result->name}» сохранён.");

        return back();
    }

    public function reorder(ReorderRequest $request, ReferenceOrder $order): RedirectResponse
    {
        $order->reorder(VisitResult::class, $request->ids(), VisitResult::AUDIT_SUBJECT, VisitResult::TITLE);

        return back();
    }

    public function status(StatusRequest $request, int $id): RedirectResponse
    {
        $result = $this->results->setActive($id, $request->boolean('active'), $request->validated('lock_version'));

        TableQuery::flashSuccess(VisitResult::SINGULAR." «{$result->name}» ".($result->active ? 'включён.' : 'отключён.'));

        return back();
    }
}
