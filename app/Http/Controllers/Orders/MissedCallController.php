<?php

namespace App\Http\Controllers\Orders;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Services\AccessScope;
use App\Services\MissedCalls;
use App\Support\TableQuery;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class MissedCallController extends Controller
{
    private const MAX_CALLS = 200;

    public function __construct(
        private readonly AccessScope $scope,
        private readonly MissedCalls $missed,
    ) {}

    /**
     * Marks the listed missed calls of the order as handled: one call, or all the calls
     * the card showed. Needs the right to change this order.
     */
    public function handle(Request $request, int $order): RedirectResponse
    {
        $model = $this->scope->orders(Order::query(), $request->user())->findOrFail($order);

        $this->authorize('update', $model);

        $data = $request->validate([
            'call_ids' => ['required', 'array', 'min:1', 'max:'.self::MAX_CALLS],
            'call_ids.*' => ['integer', 'distinct'],
        ]);

        $count = $this->missed->handle($model, $request->user(), array_map(intval(...), $data['call_ids']));

        TableQuery::flashSuccess($count === 0
            ? 'Эти пропущенные звонки уже обработаны.'
            : ($count === 1 ? 'Пропущенный звонок отмечен обработанным.' : "Пропущенные звонки отмечены обработанными: {$count}."));

        return back();
    }
}
