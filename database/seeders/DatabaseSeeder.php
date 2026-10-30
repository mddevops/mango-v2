<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

/**
 * Safe to run repeatedly. Never creates accounts: the first administrator is created
 * with `php artisan app:create-admin` or promoted with `php artisan app:promote-admin`.
 */
class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([
            RolesAndPermissionsSeeder::class,
            ReferenceDataSeeder::class,
            OrderReferenceDataSeeder::class,
        ]);
    }
}
