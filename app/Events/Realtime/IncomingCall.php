<?php

namespace App\Events\Realtime;

use App\Models\Call;
use App\Models\Order;
use App\Models\User;
use App\Services\RealtimeAudience;
use App\Support\PermissionCatalog as P;
use Illuminate\Broadcasting\PrivateChannel;

/**
 * A client's call rings at the employee mapped to the extension. With an order he may
 * open, the signal links to it; with an order he may not open, he gets nothing; a call
 * without an order gets no link. No phone number is sent.
 */
class IncomingCall extends RealtimeSignal
{
    /** @var array{order_id: int|null}|null */
    private ?array $resolved = null;

    public function __construct(
        public readonly int $legId,
        public readonly int $callId,
        public readonly int $userId,
    ) {
        parent::__construct();
    }

    public function broadcastAs(): string
    {
        return 'call.incoming';
    }

    /**
     * @return list<PrivateChannel>
     */
    public function broadcastOn(): array
    {
        return $this->resolve() === null ? [] : $this->personal([$this->userId]);
    }

    protected function payload(): array
    {
        $orderId = $this->resolve()['order_id'] ?? null;

        return [
            'call_id' => $this->callId,
            'version' => $this->legId,
            'order_id' => $orderId,
            'label' => $orderId === null ? null : "Заявка №{$orderId}",
            'url' => $orderId === null ? null : route('orders.show', $orderId, false),
        ];
    }

    /**
     * @return array{order_id: int|null}|null
     */
    private function resolve(): ?array
    {
        if ($this->resolved !== null) {
            return $this->resolved;
        }

        $orderId = Call::query()->whereKey($this->callId)->value('order_id');

        if ($orderId !== null) {
            $order = Order::query()->find($orderId);

            return $order !== null && app(RealtimeAudience::class)->allows($this->userId, 'view', $order)
                ? $this->resolved = ['order_id' => $order->id]
                : null;
        }

        $user = User::query()->where('active', true)->find($this->userId);

        return $user !== null && $user->can(P::ORDERS_VIEW) ? $this->resolved = ['order_id' => null] : null;
    }
}
