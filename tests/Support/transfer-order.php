<?php

/*
 * Transfers an order in a separate PHP process so tests can race real MySQL
 * transactions:
 *   php tests/Support/transfer-order.php {actorId} {orderId} {showroomId} {operationId} {lockVersion}
 * Prints "ready" once loaded, then "ok:{targetOrderId}" or "rejected".
 */

use App\Models\Order;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\OrderTransferManager;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Validation\ValidationException;

require __DIR__.'/../../vendor/autoload.php';

$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

[, $actorId, $orderId, $showroomId, $operationId, $lockVersion] = $argv;
$actor = User::query()->findOrFail((int) $actorId);
$order = Order::query()->findOrFail((int) $orderId);

fwrite(STDOUT, "ready\n");

try {
    $transfer = app(AuditLogger::class)->as($actor, fn () => app(OrderTransferManager::class)->transfer($order, $actor, [
        'showroom_id' => (int) $showroomId,
        'comment' => null,
        'operation_id' => $operationId,
        'lock_version' => (int) $lockVersion,
    ]));
    fwrite(STDOUT, "ok:{$transfer->target_order_id}\n");
} catch (ValidationException) {
    fwrite(STDOUT, "rejected\n");
}
