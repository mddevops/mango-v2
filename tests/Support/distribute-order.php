<?php

/*
 * Runs one distribution attempt in a separate PHP process so tests can race real
 * MySQL transactions:
 *   php tests/Support/distribute-order.php {orderId}
 * Prints "ready" once loaded, then "ok:{userId}" or "ok:none".
 */

use App\Enums\DistributionResult;
use App\Models\Order;
use App\Services\AuditLogger;
use App\Services\CallbackDistributor;
use Illuminate\Contracts\Console\Kernel;

require __DIR__.'/../../vendor/autoload.php';

$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

[, $orderId] = $argv;

fwrite(STDOUT, "ready\n");

$result = app(AuditLogger::class)->asSystem('distribution', fn () => app(CallbackDistributor::class)->attempt((int) $orderId));
$assigned = $result === DistributionResult::Assigned ? Order::query()->whereKey($orderId)->value('responsible_id') : null;
fwrite(STDOUT, 'ok:'.($assigned ?? 'none')."\n");
