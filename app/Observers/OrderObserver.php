<?php

namespace App\Observers;

use App\Models\Order;
use App\Services\RealtimePublisher;

/**
 * Every create and change of an order goes through Eloquent, so the realtime signals are
 * raised here once for the manual form, the site requests, Mango, distribution, taking
 * and transfers alike.
 */
class OrderObserver
{
    public function __construct(private readonly RealtimePublisher $realtime) {}

    public function created(Order $order): void
    {
        $this->realtime->orderCreated($order);
    }

    public function updated(Order $order): void
    {
        $this->realtime->orderUpdated($order);
    }
}
