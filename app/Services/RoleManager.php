<?php

namespace App\Services;

use App\Models\Role;
use App\Models\User;
use App\Support\PermissionCatalog;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\PermissionRegistrar;

class RoleManager
{
    public function __construct(
        private readonly AccessScope $scope,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * @param  array{name: string, label: string, permissions?: list<string>}  $data
     */
    public function create(User $editor, array $data): Role
    {
        $permissions = array_values(array_unique($data['permissions'] ?? []));
        $this->ensureDelegable($editor, $permissions);

        try {
            return $this->createRole($data, $permissions);
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages(['name' => 'Роль с таким кодом уже существует.']);
        }
    }

    /**
     * @param  array{name: string, label: string}  $data
     * @param  list<string>  $permissions
     */
    private function createRole(array $data, array $permissions): Role
    {
        return DB::transaction(function () use ($data, $permissions) {
            $role = new Role;
            $role->forceFill([
                'name' => $data['name'],
                'label' => $data['label'],
                'guard_name' => 'web',
                'is_system' => false,
            ])->save();

            $role->syncPermissions($permissions);

            $this->audit->record('role.created', 'role', $role->id, $role->displayName(), 'Создана роль', [
                'name' => $role->name,
                'label' => $role->label,
                'permissions' => array_map(PermissionCatalog::label(...), $permissions),
            ]);

            return $role;
        });
    }

    /**
     * @param  array{label: string, permissions?: list<string>, lock_version: int}  $data
     */
    public function update(User $editor, Role $role, array $data): Role
    {
        $permissions = array_values(array_unique($data['permissions'] ?? []));

        return DB::transaction(function () use ($editor, $role, $data, $permissions) {
            /** @var Role $locked */
            $locked = Role::query()->with('permissions')->lockForUpdate()->findOrFail($role->id);
            $locked->ensureLockVersion($data['lock_version']);

            $current = $locked->permissionNames();

            if ($locked->isAdministrator()) {
                sort($permissions);
                $all = PermissionCatalog::all();
                sort($all);

                if ($permissions !== $all) {
                    throw ValidationException::withMessages([
                        'permissions' => 'Разрешения системной роли «Администратор» изменить нельзя.',
                    ]);
                }
            }

            $this->ensureDelegable($editor, $current);
            $this->ensureDelegable($editor, $permissions);

            $added = array_values(array_diff($permissions, $current));
            $removed = array_values(array_diff($current, $permissions));
            $labelChanged = $locked->label !== $data['label'];

            if (! $labelChanged && $added === [] && $removed === []) {
                return $locked;
            }

            $oldLabel = $locked->label;
            $locked->label = $data['label'];
            $locked->bumpLockVersion()->save();
            $locked->syncPermissions($permissions);

            $changes = [];
            if ($labelChanged) {
                $changes['label'] = [$oldLabel, $locked->label];
            }
            if ($added !== [] || $removed !== []) {
                $changes['permissions'] = [
                    'added' => array_map(PermissionCatalog::label(...), $added),
                    'removed' => array_map(PermissionCatalog::label(...), $removed),
                ];
            }

            $this->audit->record('role.updated', 'role', $locked->id, $locked->displayName(), 'Изменена роль', $changes);

            return $locked;
        });
    }

    public function delete(User $editor, Role $role): void
    {
        DB::transaction(function () use ($editor, $role) {
            /** @var Role $locked */
            $locked = Role::query()->with('permissions')->lockForUpdate()->findOrFail($role->id);

            if ($locked->is_system || $locked->isAdministrator()) {
                throw ValidationException::withMessages(['role' => 'Системную роль удалить нельзя.']);
            }

            if ($locked->users()->exists()) {
                throw ValidationException::withMessages(['role' => 'Роль назначена сотрудникам. Сначала снимите её с сотрудников.']);
            }

            $this->ensureDelegable($editor, $locked->permissionNames());

            $label = $locked->displayName();
            $id = $locked->id;
            $locked->delete();

            app(PermissionRegistrar::class)->forgetCachedPermissions();

            $this->audit->record('role.deleted', 'role', $id, $label, 'Удалена роль');
        });
    }

    /**
     * @param  list<string>  $permissions
     */
    private function ensureDelegable(User $editor, array $permissions): void
    {
        $unknown = array_diff($permissions, PermissionCatalog::all());

        if ($unknown !== []) {
            throw ValidationException::withMessages(['permissions' => 'Выбрано неизвестное разрешение.']);
        }

        if (! $this->scope->canDelegatePermissions($editor, $permissions)) {
            throw ValidationException::withMessages([
                'permissions' => 'Нельзя выдавать или изменять разрешения, которых нет у вас самих.',
            ]);
        }
    }
}
