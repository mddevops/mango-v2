<?php

namespace App\Events\Realtime;

use App\Models\Call;
use App\Models\Order;
use App\Services\RealtimeAudience;
use Illuminate\Broadcasting\PrivateChannel;

/**
 * A call of an order or its recording changed: the open card reloads its calls. The
 * order is read when the signal is sent; a call without an order reaches nobody. Call
 * changes go to those who may see the history, recording changes only to those who may
 * also listen to recordings.
 */
class CallChanged extends RealtimeSignal
{
    public function __construct(
        public readonly int $callId,
        public readonly int $version,
        public readonly string $ability = 'viewHistory',
    ) {
        parent::__construct();
    }

    public function broadcastAs(): string
    {
        return 'call.updated';
    }

    /**
     * @return list<PrivateChannel>
     */
    public function broadcastOn(): array
    {
        $order = $this->order();

        return $order === null ? [] : $this->personal(app(RealtimeAudience::class)->users($order, $this->ability));
    }

    protected function payload(): array
    {
        return ['order_id' => $this->order()?->id, 'call_id' => $this->callId, 'version' => $this->version];
    }

    private function order(): ?Order
    {
        $orderId = Call::query()->whereKey($this->callId)->value('order_id');

        return $orderId === null ? null : Order::query()->find($orderId);
    }
}
