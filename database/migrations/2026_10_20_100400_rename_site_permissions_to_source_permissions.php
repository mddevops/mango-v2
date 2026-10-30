<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\PermissionRegistrar;

/**
 * Renames permission rows in place, so every role keeps exactly the access it had.
 */
return new class extends Migration
{
    private const RENAMES = [
        'sites.view' => 'sources.view',
        'sites.manage' => 'sources.manage',
        'site_references.view' => 'source_references.view',
        'site_references.manage' => 'source_references.manage',
    ];

    public function up(): void
    {
        $this->rename(self::RENAMES);
    }

    public function down(): void
    {
        $this->rename(array_flip(self::RENAMES));
    }

    /**
     * @param  array<string, string>  $renames
     */
    private function rename(array $renames): void
    {
        $table = config('permission.table_names.permissions', 'permissions');

        foreach ($renames as $from => $to) {
            if (! DB::table($table)->where('name', $to)->exists()) {
                DB::table($table)->where('name', $from)->update(['name' => $to, 'updated_at' => now()]);
            }
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
