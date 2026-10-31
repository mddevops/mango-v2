<?php

namespace App\Services;

use App\Events\Realtime\CallCard;
use App\Events\Realtime\CallChanged;
use App\Events\Realtime\OrderAccessCheck;
use App\Events\Realtime\OrderAssigned;
use App\Events\Realtime\OrderChanged;
use App\Events\Realtime\RealtimeSignal;
use App\Models\Order;
use Illuminate\Broadcasting\BroadcastManager;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Raises realtime signals after the surrounding transaction commits (a rolled back change
 * sends nothing). A failure to queue a signal is logged and swallowed: the order, the
 * assignment or the Mango event is already saved, and the pages reload the actual state
 * after a reconnect anyway.
 */
class RealtimePublisher
{
    public function __construct(private readonly BroadcastManager $broadcasting) {}

    public function orderCreated(Order $order): void
    {
        $signals = [new OrderChanged($order->id, $order->lock_version)];

        if ($order->responsible_id !== null && $order->responsible_id !== auth()->id()) {
            $signals[] = new OrderAssigned($order->id, $order->responsible_id, $order->lock_version);
        }

        $this->publish(...$signals);
    }

    /**
     * Called from the `updated` model event, where the original still holds the previous
     * responsible.
     */
    public function orderUpdated(Order $order): void
    {
        $signals = [new OrderChanged($order->id, $order->lock_version)];

        if ($order->wasChanged('responsible_id')) {
            $previous = $order->getOriginal('responsible_id');

            if ($order->responsible_id !== null && $order->responsible_id !== auth()->id()) {
                $signals[] = new OrderAssigned($order->id, $order->responsible_id, $order->lock_version);
            }

            if ($previous !== null) {
                $signals[] = new OrderAccessCheck($order->id, (int) $previous);
            }
        }

        $this->publish(...$signals);
    }

    /**
     * The order's lists changed without a change of the order itself: a missed call came
     * or was handled. The version stays the order's own.
     */
    public function orderTouched(int $orderId): void
    {
        $version = Order::query()->whereKey($orderId)->value('lock_version');

        if ($version !== null) {
            $this->publish(new OrderChanged($orderId, (int) $version));
        }
    }

    public function callChanged(int $callId): void
    {
        $this->publish(new CallChanged($callId, $this->clock()));
    }

    public function recordingChanged(int $callId): void
    {
        $this->publish(new CallChanged($callId, $this->clock(), 'listenRecordings'));
    }

    public function callCard(int $callId, int $userId): void
    {
        $this->publish(new CallCard($callId, $userId));
    }

    public function publish(RealtimeSignal ...$signals): void
    {
        DB::afterCommit(function () use ($signals) {
            foreach ($signals as $signal) {
                try {
                    $this->broadcasting->queue($signal);
                } catch (Throwable $exception) {
                    Log::warning('Realtime signal was not queued.', ['signal' => $signal->broadcastAs(), 'error' => $exception::class]);
                }
            }
        });
    }

    /** Orders calls' signals in time; the browser ignores an older one. */
    private function clock(): int
    {
        return (int) floor(microtime(true) * 1000);
    }
}
