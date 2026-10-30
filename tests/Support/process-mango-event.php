<?php

/*
 * Processes one saved Mango event in a separate PHP process so tests can race real
 * MySQL transactions:
 *   php tests/Support/process-mango-event.php {eventId}
 * Prints "ready" once loaded, then "ok:{state}".
 */

use App\Models\MangoEvent;
use App\Services\MangoEventProcessor;
use Illuminate\Contracts\Console\Kernel;

require __DIR__.'/../../vendor/autoload.php';

$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

[, $eventId] = $argv;

fwrite(STDOUT, "ready\n");

app(MangoEventProcessor::class)->process((int) $eventId);
fwrite(STDOUT, 'ok:'.MangoEvent::query()->findOrFail((int) $eventId)->state->value."\n");
