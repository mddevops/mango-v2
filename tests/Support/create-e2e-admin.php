<?php

/*
 * Creates the administrator used by the Playwright scenario.
 * Refuses to run against anything but a *_test database.
 * Usage: E2E_ADMIN_EMAIL=... E2E_ADMIN_PASSWORD=... php tests/Support/create-e2e-admin.php
 */

use App\Models\User;
use App\Support\RoleCatalog;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

require __DIR__.'/../../vendor/autoload.php';

$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$database = DB::connection()->getDatabaseName();

if (! str_ends_with($database, '_test')) {
    fwrite(STDERR, "Refusing to create the e2e administrator in database {$database}.\n");
    exit(1);
}

$email = (string) getenv('E2E_ADMIN_EMAIL');
$password = (string) getenv('E2E_ADMIN_PASSWORD');

if ($email === '' || strlen($password) < 12) {
    fwrite(STDERR, "E2E_ADMIN_EMAIL and E2E_ADMIN_PASSWORD (12+ chars) are required.\n");
    exit(1);
}

$user = User::query()->firstOrNew(['email' => $email]);
$user->forceFill([
    'name' => 'E2E Администратор',
    'password' => Hash::make($password),
    'active' => true,
    'has_global_access' => true,
    'email_verified_at' => now(),
])->save();
$user->syncRoles([RoleCatalog::ADMINISTRATOR]);

fwrite(STDOUT, "e2e administrator #{$user->id} ready in {$database}\n");
