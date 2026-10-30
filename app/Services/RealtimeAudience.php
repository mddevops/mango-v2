<?php

namespace App\Services;

use App\Models\Order;
use App\Models\User;
use App\Support\PermissionCatalog as P;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Gate;

/**
 * Who may get a realtime signal about an order right now. Asked when the signal is sent
 * (in the broadcast worker), not when it was raised: a deactivated employee or one who
 * lost a right gets nothing new even with an open connection. The decision is the order
 * policy itself, so the signals follow the same rules as the pages.
 */
class RealtimeAudience
{
    /**
     * @return list<int>
     */
    public function users(Order $order, string $ability): array
    {
        return User::query()
            ->where('active', true)
            ->permission(P::ORDERS_VIEW)
            ->where(fn (Builder $query) => $query
                ->where('has_global_access', true)
                ->orWhereHas('showrooms', fn (Builder $showrooms) => $showrooms->where('showrooms.id', $order->showroom_id)))
            ->get()
            ->filter(fn (User $user) => Gate::forUser($user)->allows($ability, $order))
            ->map(fn (User $user) => $user->id)
            ->values()
            ->all();
    }

    public function allows(int $userId, string $ability, Order $order): bool
    {
        $user = User::query()->where('active', true)->find($userId);

        return $user !== null && Gate::forUser($user)->allows($ability, $order);
    }
}
