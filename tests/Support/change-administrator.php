<?php

/*
 * Runs one administrator-affecting change in a separate PHP process so tests can
 * race real MySQL transactions:
 *   php tests/Support/change-administrator.php {deactivate|remove-role|revoke-global} {editorId} {targetId}
 * Prints "ready" once loaded, then "ok" or "rejected".
 */

use App\Models\User;
use App\Services\UserManager;
use App\Support\RoleCatalog;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Validation\ValidationException;

require __DIR__.'/../../vendor/autoload.php';

$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

[, $action, $editorId, $targetId] = $argv;

$editor = User::query()->findOrFail((int) $editorId);
$target = User::query()->findOrFail((int) $targetId);
$manager = app(UserManager::class);

// Everything the change needs is read before the race starts, like a submitted form.
$lockVersion = $target->lock_version;
$roleIds = $target->roles()->where('name', '!=', RoleCatalog::ADMINISTRATOR)->pluck('roles.id')->all();
$showroomIds = $target->showrooms()->pluck('showrooms.id')->all();
$departmentIds = $target->departments()->pluck('departments.id')->all();

fwrite(STDOUT, "ready\n");

try {
    match ($action) {
        'deactivate' => $manager->setActive($editor, $target, false, $lockVersion),
        'revoke-global' => $manager->setGlobalAccess($editor, $target, false, $lockVersion),
        'remove-role' => $manager->update($editor, $target, [
            'name' => $target->name,
            'email' => $target->email,
            'lock_version' => $lockVersion,
            'role_ids' => $roleIds,
            'showroom_ids' => $showroomIds,
            'department_ids' => $departmentIds,
        ]),
    };
    fwrite(STDOUT, "ok\n");
} catch (ValidationException) {
    fwrite(STDOUT, "rejected\n");
}
