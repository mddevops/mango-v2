<?php

namespace Database\Seeders;

use App\Models\Role;
use App\Support\PermissionCatalog;
use App\Support\RoleCatalog;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

/**
 * Creates missing permissions and roles. Existing roles keep their label and permission
 * set as edited in the interface; only the administrator role is kept complete,
 * because it is a protected system role.
 */
class RolesAndPermissionsSeeder extends Seeder
{
    public function run(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        DB::transaction(function () {
            foreach (PermissionCatalog::all() as $name) {
                Permission::findOrCreate($name, 'web');
            }

            foreach (RoleCatalog::defaults() as $name => $definition) {
                /** @var Role|null $role */
                $role = Role::query()->where('name', $name)->where('guard_name', 'web')->first();

                if ($role === null) {
                    $role = new Role;
                    $role->forceFill([
                        'name' => $name,
                        'label' => $definition['label'],
                        'guard_name' => 'web',
                        'is_system' => $name === RoleCatalog::ADMINISTRATOR,
                    ])->save();

                    $role->syncPermissions($definition['permissions']);

                    continue;
                }

                if ($role->label === null) {
                    $role->forceFill(['label' => $definition['label']])->save();
                }

                if ($role->isAdministrator()) {
                    if (! $role->is_system) {
                        $role->forceFill(['is_system' => true])->save();
                    }

                    $missing = array_diff(PermissionCatalog::all(), $role->permissions()->pluck('name')->all());

                    if ($missing !== []) {
                        $role->givePermissionTo($missing);
                    }
                }
            }
        });

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
}
