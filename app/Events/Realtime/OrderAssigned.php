<?php

namespace App\Events\Realtime;

use App\Models\Order;
use App\Services\RealtimeAudience;
use Illuminate\Broadcasting\PrivateChannel;

/**
 * The employee became the responsible of an order. Sent only while he still is and may
 * open it; the link leads to that order only.
 */
class OrderAssigned extends RealtimeSignal
{
    public function __construct(
        public readonly int $orderId,
        public readonly int $userId,
        public readonly int $version,
    ) {
        parent::__construct();
    }

    public function broadcastAs(): string
    {
        return 'order.assigned';
    }

    /**
     * @return list<PrivateChannel>
     */
    public function broadcastOn(): array
    {
        $order = Order::query()->find($this->orderId);

        if ($order === null || $order->responsible_id !== $this->userId || ! app(RealtimeAudience::class)->allows($this->userId, 'view', $order)) {
            return [];
        }

        return $this->personal([$this->userId]);
    }

    protected function payload(): array
    {
        return [
            'order_id' => $this->orderId,
            'version' => $this->version,
            'label' => "Заявка №{$this->orderId}",
            'url' => route('orders.show', $this->orderId, false),
        ];
    }
}
