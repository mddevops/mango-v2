<?php

/*
 * Creates one manual order in a separate PHP process so tests can race real MySQL
 * transactions:
 *   php tests/Support/create-order.php {actorId} {sourceId} {channelId} {phone}
 * Prints "ready" once loaded, then "ok:{repeatNumber}" or "rejected".
 */

use App\Models\Source;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\OrderManager;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Validation\ValidationException;

require __DIR__.'/../../vendor/autoload.php';

$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

[, $actorId, $sourceId, $channelId, $phone] = $argv;
$actor = User::query()->findOrFail((int) $actorId);

fwrite(STDOUT, "ready\n");

try {
    $order = app(AuditLogger::class)->as($actor, fn () => app(OrderManager::class)->create($actor, [
        'showroom_id' => Source::query()->whereKey((int) $sourceId)->value('showroom_id'),
        'source_id' => (int) $sourceId,
        'contact_channel_id' => (int) $channelId,
        'contact_name' => 'Клиент',
        'phones' => [$phone],
        'phone_names' => [],
        'car_interest' => null,
    ]));
    fwrite(STDOUT, "ok:{$order->repeat_number}\n");
} catch (ValidationException) {
    fwrite(STDOUT, "rejected\n");
}
