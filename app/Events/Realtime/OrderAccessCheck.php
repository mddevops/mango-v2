<?php

namespace App\Events\Realtime;

use App\Models\Order;
use App\Models\User;
use App\Services\RealtimeAudience;
use Illuminate\Broadcasting\PrivateChannel;

/**
 * The previous responsible of a reassigned order: only «check your access to order N».
 * Nothing about the new state is sent; if he still may see the order, the usual
 * OrderChanged reaches him and this signal is not needed.
 */
class OrderAccessCheck extends RealtimeSignal
{
    public function __construct(public readonly int $orderId, public readonly int $userId)
    {
        parent::__construct();
    }

    public function broadcastAs(): string
    {
        return 'order.access-check';
    }

    /**
     * @return list<PrivateChannel>
     */
    public function broadcastOn(): array
    {
        $order = Order::query()->find($this->orderId);

        if ($order !== null && app(RealtimeAudience::class)->allows($this->userId, 'view', $order)) {
            return [];
        }

        return User::query()->whereKey($this->userId)->where('active', true)->exists() ? $this->personal([$this->userId]) : [];
    }

    protected function payload(): array
    {
        return ['order_id' => $this->orderId];
    }
}
