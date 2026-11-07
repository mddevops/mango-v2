<?php

namespace App\Http\Controllers\Orders;

use App\Http\Controllers\Controller;
use App\Http\Requests\Orders\PaymentMethodRequest;
use App\Http\Requests\Organization\StatusRequest;
use App\Http\Requests\ReorderRequest;
use App\Models\PaymentMethod;
use App\Models\User;
use App\Services\AccessScope;
use App\Services\PaymentMethodManager;
use App\Services\ReferenceOrder;
use App\Support\TableFilter;
use App\Support\TableFilters;
use App\Support\TableQuery;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class PaymentMethodController extends Controller
{
    public function __construct(
        private readonly PaymentMethodManager $methods,
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

        $paginator = $filters->apply(PaymentMethod::query())
            ->withCount(['orders' => fn (Builder $orders) => $this->scope->orders($orders, $user)])
            ->orderBy('sort_order')
            ->orderBy('id')
            ->paginate(TableQuery::perPage($request))
            ->withQueryString();

        return Inertia::render('order-references/payment-methods', [
            'items' => TableQuery::paginated($paginator, fn (PaymentMethod $method) => [
                'id' => $method->id,
                'code' => $method->code,
                'name' => $method->name,
                'active' => $method->active,
                'orders_count' => (int) $method->getAttribute('orders_count'),
                'lock_version' => $method->lock_version,
            ]),
            'filters' => [
                'filter' => $filters->conditions,
                'per_page' => TableQuery::perPage($request),
            ],
            'can' => ['manage' => Gate::allows('order-references.manage')],
        ]);
    }

    public function store(PaymentMethodRequest $request): RedirectResponse
    {
        $method = $this->methods->create($request->name());

        TableQuery::flashSuccess(PaymentMethod::SINGULAR." «{$method->name}» создан.");

        return back();
    }

    public function update(PaymentMethodRequest $request, int $id): RedirectResponse
    {
        $method = $this->methods->update($id, $request->name(), $request->lockVersion());

        TableQuery::flashSuccess(PaymentMethod::SINGULAR." «{$method->name}» сохранён.");

        return back();
    }

    public function reorder(ReorderRequest $request, ReferenceOrder $order): RedirectResponse
    {
        $order->reorder(PaymentMethod::class, $request->ids(), PaymentMethod::AUDIT_SUBJECT, PaymentMethod::TITLE);

        return back();
    }

    public function status(StatusRequest $request, int $id): RedirectResponse
    {
        $method = $this->methods->setActive($id, $request->boolean('active'), $request->validated('lock_version'));

        TableQuery::flashSuccess(PaymentMethod::SINGULAR." «{$method->name}» ".($method->active ? 'включён.' : 'отключён.'));

        return back();
    }
}
