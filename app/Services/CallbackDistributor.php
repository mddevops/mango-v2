<?php

namespace App\Services;

use App\Enums\DistributionResult;
use App\Enums\DistributionState;
use App\Models\ContactChannel;
use App\Models\DistributionSchedule;
use App\Models\Order;
use App\Models\Showroom;
use App\Models\User;
use App\Support\BusinessDayRule;
use App\Support\PermissionCatalog as P;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * The one place that assigns unassigned callback orders handed to distribution.
 *
 * Candidates are active employees allowed to view orders, with access to the order's
 * showroom and a schedule in that showroom that takes part and works on the order's
 * stored business date. Schedules of other showrooms are never looked at. A repeat order
 * goes to the responsible of the client's latest assigned earlier order in the showroom
 * when he is a candidate; otherwise the candidate with the fewest primary callback orders
 * of that showroom and business day wins, then the one who waited longest for an
 * automatic assignment in that showroom, then the lowest id.
 *
 * Each attempt locks the showroom row and then the order row, so attempts in one
 * showroom are serialized and their load counts are consistent; manual edits lock only
 * the order, so the lock order never inverts. An order that is no longer pending is left
 * as it is. Only the assignment itself is audited; the reasons of the choice are not kept.
 */
class CallbackDistributor
{
    public const NOT_CALLBACK = 'В распределение передаются только заявки обратного звонка.';

    public const ALREADY_ASSIGNED = 'У заявки уже есть ответственный.';

    public function __construct(
        private readonly AuditLogger $audit,
        private readonly BusinessDayCalculator $businessDays,
    ) {}

    /**
     * Explicitly hands an unassigned callback order to distribution and tries it at once.
     * Used for orders waiting already, too, as a manual retry.
     */
    public function request(Order $order, ?User $actor): ?DistributionResult
    {
        DB::transaction(function () use ($order) {
            /** @var Order $locked */
            $locked = Order::query()->lockForUpdate()->findOrFail($order->id);

            if ($locked->responsible_id !== null) {
                throw ValidationException::withMessages(['distribution' => self::ALREADY_ASSIGNED]);
            }

            if ($locked->contact_channel_id !== $this->callbackChannelId()) {
                throw ValidationException::withMessages(['distribution' => self::NOT_CALLBACK]);
            }

            if ($locked->distribution_state !== DistributionState::Pending) {
                $this->quietly($locked, DistributionState::Pending);
            }
        });

        return $this->attempt($order->id, $actor);
    }

    /**
     * One attempt for one order. Returns null when the order was not waiting (already
     * assigned, assigned by hand or never handed over).
     */
    public function attempt(int $orderId, ?User $actor = null): ?DistributionResult
    {
        $showroomId = Order::query()->whereKey($orderId)->value('showroom_id');

        if ($showroomId === null) {
            return null;
        }

        return DB::transaction(function () use ($orderId, $showroomId, $actor) {
            Showroom::query()->whereKey($showroomId)->lockForUpdate()->first();

            /** @var Order|null $order */
            $order = Order::query()->lockForUpdate()->find($orderId);

            if ($order === null || $order->distribution_state !== DistributionState::Pending) {
                return null;
            }

            if ($order->responsible_id !== null) {
                $this->quietly($order, null);

                return null;
            }

            $now = CarbonImmutable::now();
            $period = $this->businessDays->forDate(BusinessDayRule::fromSnapshot($order->business_day_rule), $order->business_date);

            if ($now->gte($period->end)) {
                $this->quietly($order, DistributionState::Expired);

                return DistributionResult::Expired;
            }

            $candidates = $this->candidates($order);

            if ($candidates->isEmpty()) {
                return DistributionResult::Waiting;
            }

            $previous = $this->previousResponsible($order);
            $chosen = $previous !== null && $candidates->contains('id', $previous)
                ? $candidates->firstWhere('id', $previous)
                : $candidates->first();

            $order->responsible_id = $chosen['id'];
            $order->distribution_state = null;
            $order->bumpLockVersion()->save();

            DistributionSchedule::query()->whereKey($chosen['schedule_id'])->update(['last_assigned_at' => $now]);

            $this->asActor($actor, fn () => $this->audit->record(
                'order.responsible_changed',
                'order',
                $order->id,
                "Заявка №{$order->id}",
                'Назначен ответственный распределением',
                ['responsible' => [null, $chosen['name']]],
                [$order->showroom_id],
            ));

            return DistributionResult::Assigned;
        });
    }

    /**
     * Retries waiting orders in small batches, oldest first. Safe to run repeatedly: every
     * order is re-checked under its locks.
     *
     * @return array{processed: int, assigned: int, waiting: int, expired: int}
     */
    public function retryPending(int $limit): array
    {
        $summary = ['processed' => 0, 'assigned' => 0, 'waiting' => 0, 'expired' => 0];

        $ids = Order::query()
            ->where('distribution_state', DistributionState::Pending)
            ->orderBy('id')
            ->limit($limit)
            ->pluck('id');

        foreach ($ids as $id) {
            $result = $this->audit->asSystem('distribution', fn () => $this->attempt((int) $id));
            $summary['processed']++;

            if ($result !== null) {
                $summary[$result->value]++;
            }
        }

        return $summary;
    }

    public function callbackChannelId(): ?int
    {
        $id = ContactChannel::query()->where('code', ContactChannel::CALLBACK)->value('id');

        return $id === null ? null : (int) $id;
    }

    /**
     * Candidates sorted by the least-load rule.
     *
     * @return Collection<int, array{id: int, name: string, schedule_id: int, load: int, last_assigned_at: string|null}>
     */
    private function candidates(Order $order): Collection
    {
        $weekday = DistributionSchedule::WEEKDAYS[CarbonImmutable::parse($order->business_date)->dayOfWeekIso];

        $users = User::query()
            ->select(['users.id', 'users.name', 'schedule.id as schedule_id', 'schedule.last_assigned_at'])
            ->join('distribution_schedules as schedule', fn ($join) => $join
                ->on('schedule.user_id', '=', 'users.id')
                ->where('schedule.showroom_id', '=', $order->showroom_id))
            ->leftJoin('distribution_schedule_exceptions as exception', fn ($join) => $join
                ->on('exception.distribution_schedule_id', '=', 'schedule.id')
                ->where('exception.date', '=', $order->business_date))
            ->where('users.active', true)
            ->where('schedule.participates', true)
            ->where(fn (Builder $works) => $works
                ->where('exception.works', true)
                ->orWhere(fn (Builder $weekly) => $weekly->whereNull('exception.id')->where("schedule.{$weekday}", true)))
            ->where(fn (Builder $access) => $access
                ->where('users.has_global_access', true)
                ->orWhereHas('showrooms', fn (Builder $showrooms) => $showrooms->where('showrooms.id', $order->showroom_id)))
            ->permission(P::ORDERS_VIEW)
            ->orderBy('users.id')
            ->get();

        if ($users->isEmpty()) {
            return collect();
        }

        $loads = Order::query()
            ->where('showroom_id', $order->showroom_id)
            ->where('business_date', $order->business_date)
            ->where('contact_channel_id', $this->callbackChannelId())
            ->where('repeat_number', 0)
            ->whereIn('responsible_id', $users->modelKeys())
            ->groupBy('responsible_id')
            ->selectRaw('responsible_id, count(*) as aggregate')
            ->pluck('aggregate', 'responsible_id');

        return $users
            ->map(fn (User $user) => [
                'id' => $user->id,
                'name' => $user->name,
                'schedule_id' => (int) $user->getAttribute('schedule_id'),
                'load' => (int) ($loads[$user->id] ?? 0),
                'last_assigned_at' => $user->getAttribute('last_assigned_at') === null ? null : CarbonImmutable::parse($user->getAttribute('last_assigned_at'))->toIso8601String(),
            ])
            ->sort(fn (array $a, array $b) => [$a['load'], $a['last_assigned_at'] !== null, $a['last_assigned_at'], $a['id']]
                <=> [$b['load'], $b['last_assigned_at'] !== null, $b['last_assigned_at'], $b['id']])
            ->values();
    }

    /**
     * The responsible of the client's latest earlier order in the showroom that has one.
     * Read internally; nothing of that order is shown to the operator.
     */
    private function previousResponsible(Order $order): ?int
    {
        $id = Order::query()
            ->where('client_id', $order->client_id)
            ->where('showroom_id', $order->showroom_id)
            ->where('id', '<', $order->id)
            ->whereNotNull('responsible_id')
            ->orderByDesc('id')
            ->value('responsible_id');

        return $id === null ? null : (int) $id;
    }

    /**
     * Distribution bookkeeping is not a change of the order: `updated_at` (used to match
     * future calls) and the form version stay as they are.
     */
    private function quietly(Order $order, ?DistributionState $state): void
    {
        $order->distribution_state = $state;
        $order->timestamps = false;
        $order->save();
        $order->timestamps = true;
    }

    /**
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    private function asActor(?User $actor, callable $callback): mixed
    {
        return $actor === null ? $this->audit->asSystem('distribution', $callback) : $this->audit->as($actor, $callback);
    }
}
