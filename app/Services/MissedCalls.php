<?php

namespace App\Services;

use App\Models\Call;
use App\Models\Order;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Missed calls of an order that still need an employee's attention. A missed call is
 * closed only by an explicit action: opening the card, changing the status, a later call
 * or a repeated Mango event do not close it. The call and its result are never changed.
 */
class MissedCalls
{
    public function __construct(
        private readonly AuditLogger $audit,
        private readonly RealtimePublisher $realtime,
    ) {}

    /**
     * @return list<array{id: int, started_at: string|null, source: string|null, line: string|null}>
     */
    public function pending(Order $order): array
    {
        return Call::query()
            ->unhandledMissed()
            ->where('order_id', $order->id)
            ->with(['source:id,name', 'line:id,value'])
            ->orderByDesc('started_at')
            ->orderByDesc('id')
            ->get()
            ->map(fn (Call $call) => [
                'id' => $call->id,
                'started_at' => $call->started_at?->toISOString(),
                'source' => $call->source?->name,
                'line' => $call->line?->value ?? $call->line_number,
            ])
            ->values()
            ->all();
    }

    /**
     * Marks as handled only the listed calls of this order that are still unhandled
     * missed ones — the calls the employee saw; a call that came meanwhile stays open.
     * Repeating the action changes nothing and writes no audit.
     *
     * @param  list<int>  $callIds
     * @return int how many calls were marked now
     */
    public function handle(Order $order, User $user, array $callIds): int
    {
        $handled = DB::transaction(function () use ($order, $user, $callIds) {
            /** @var Collection<int, Call> $calls */
            $calls = Call::query()
                ->unhandledMissed()
                ->where('order_id', $order->id)
                ->whereIn('id', $callIds)
                ->orderBy('id')
                ->lockForUpdate()
                ->get(['id', 'started_at']);

            if ($calls->isEmpty()) {
                return $calls;
            }

            Call::query()->whereKey($calls->modelKeys())->update([
                'missed_handled_at' => CarbonImmutable::now(),
                'missed_handled_by_id' => $user->id,
            ]);

            $this->audit->as($user, fn () => $this->audit->record(
                'order.missed_calls_handled',
                'order',
                $order->id,
                "Заявка №{$order->id}",
                $calls->count() === 1 ? 'Пропущенный звонок отмечен обработанным' : 'Пропущенные звонки отмечены обработанными',
                ['calls' => $calls->modelKeys(), 'count' => $calls->count()],
                [$order->showroom_id],
            ));

            return $calls;
        });

        if ($handled->isNotEmpty()) {
            $this->realtime->orderTouched($order->id);
        }

        return $handled->count();
    }
}
