<?php

/*
 * Takes an order or assigns it manually in a separate PHP process so tests can race
 * real MySQL transactions:
 *   php tests/Support/take-order.php take {actorId} {orderId}
 *   php tests/Support/take-order.php assign {actorId} {orderId} {responsibleId} {lockVersion}
 * Prints "ready" once loaded, then "ok" or "rejected".
 */

use App\Models\Order;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\OrderManager;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Validation\ValidationException;

require __DIR__.'/../../vendor/autoload.php';

$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

[, $mode, $actorId, $orderId] = $argv;
$actor = User::query()->findOrFail((int) $actorId);
$order = Order::query()->with('additionalPhones')->findOrFail((int) $orderId);

fwrite(STDOUT, "ready\n");

try {
    app(AuditLogger::class)->as($actor, fn () => $mode === 'take'
        ? app(OrderManager::class)->take($order, $actor)
        : app(OrderManager::class)->update($order, [
            'contact_channel_id' => $order->contact_channel_id,
            'contact_name' => $order->contact_name,
            'phones' => array_column($order->contactPhones(), 'phone'),
            'phone_names' => [],
            'car_interest' => $order->car_interest,
            'order_status_id' => $order->order_status_id,
            'callback_at' => null,
            'arrival_at' => null,
            'reason_id' => null,
            'reason_comment' => null,
            'lock_version' => (int) $argv[5],
            'responsible_id' => (int) $argv[4],
            'assign_any' => true,
        ]));
    fwrite(STDOUT, "ok\n");
} catch (ValidationException) {
    fwrite(STDOUT, "rejected\n");
}
