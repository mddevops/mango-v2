<?php

namespace App\Events\Realtime;

use App\Models\Order;
use App\Services\RealtimeAudience;
use Illuminate\Broadcasting\PrivateChannel;

/**
 * An order was created or changed: lists and an open card of everyone who may see it
 * reload their data.
 */
class OrderChanged extends RealtimeSignal
{
    public function __construct(public readonly int $orderId, public readonly int $version)
    {
        parent::__construct();
    }

    public function broadcastAs(): string
    {
        return 'order.updated';
    }

    /**
     * @return list<PrivateChannel>
     */
    public function broadcastOn(): array
    {
        $order = Order::query()->find($this->orderId);

        return $order === null ? [] : $this->personal(app(RealtimeAudience::class)->users($order, 'view'));
    }

    protected function payload(): array
    {
        return ['order_id' => $this->orderId, 'version' => $this->version];
    }
}
