<?php

/*
 * Creates one showroom or department in a separate PHP process so tests can race
 * real MySQL transactions:
 *   php tests/Support/create-organization-unit.php showroom {name}
 *   php tests/Support/create-organization-unit.php department {name} {showroomId|shared}
 * Prints "ready" once loaded, then "ok:{code}" or "rejected".
 */

use App\Services\OrganizationManager;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Validation\ValidationException;

require __DIR__.'/../../vendor/autoload.php';

$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

[, $type, $name] = $argv;
$scope = $argv[3] ?? 'shared';
$manager = app(OrganizationManager::class);

fwrite(STDOUT, "ready\n");

try {
    $model = match ($type) {
        'showroom' => $manager->createShowroom(['name' => $name, 'timezone' => 'Europe/Moscow']),
        'department' => $manager->createDepartment([
            'showroom_id' => $scope === 'shared' ? null : (int) $scope,
            'name' => $name,
            'kind' => $scope === 'shared' ? 'other' : 'showroom',
        ]),
    };
    fwrite(STDOUT, "ok:{$model->code}\n");
} catch (ValidationException) {
    fwrite(STDOUT, "rejected\n");
}
