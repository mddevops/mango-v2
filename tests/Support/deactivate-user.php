<?php

/*
 * Runs one deactivation in a separate PHP process so tests can race real
 * MySQL transactions: php tests/Support/deactivate-user.php {editorId} {targetId}
 */

use App\Models\User;
use App\Services\UserManager;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Validation\ValidationException;

require __DIR__.'/../../vendor/autoload.php';

$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

[, $editorId, $targetId] = $argv;

$editor = User::query()->findOrFail((int) $editorId);
$target = User::query()->findOrFail((int) $targetId);

fwrite(STDOUT, "ready\n");

try {
    app(UserManager::class)->setActive($editor, $target, false, $target->lock_version);
    fwrite(STDOUT, "ok\n");
} catch (ValidationException) {
    fwrite(STDOUT, "rejected\n");
}
