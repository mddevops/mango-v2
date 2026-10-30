<?php

namespace App\Services;

use App\Models\Department;
use App\Models\Role;
use App\Models\Showroom;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class UserManager
{
    public function __construct(
        private readonly AccessScope $scope,
        private readonly AuditLogger $audit,
        private readonly AdministratorGuard $administrators,
    ) {}

    /**
     * @param  array{name: string, email: string, password: string, active?: bool, role_ids?: list<int>, showroom_ids?: list<int>, department_ids?: list<int>}  $data
     */
    public function create(User $editor, array $data): User
    {
        try {
            return $this->createUser($editor, $data);
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages(['email' => 'Сотрудник с таким email уже существует.']);
        }
    }

    /**
     * @param  array{name: string, email: string, password: string, active?: bool, role_ids?: list<int>, showroom_ids?: list<int>, department_ids?: list<int>}  $data
     */
    private function createUser(User $editor, array $data): User
    {
        return DB::transaction(function () use ($editor, $data) {
            $assignments = $this->resolveAssignments(
                $editor,
                null,
                $data['role_ids'] ?? [],
                $data['showroom_ids'] ?? [],
                $data['department_ids'] ?? [],
            );

            $user = new User;
            $user->forceFill([
                'name' => $data['name'],
                'email' => $data['email'],
                'password' => Hash::make($data['password']),
                'active' => $data['active'] ?? true,
                'email_verified_at' => now(),
            ])->save();

            $user->syncRoles($assignments['roles']);
            $user->showrooms()->sync($assignments['showrooms']->modelKeys());
            $user->departments()->sync($assignments['departments']->modelKeys());

            $this->audit->record(
                'user.created',
                'user',
                $user->id,
                $user->name,
                'Создан сотрудник',
                [
                    'name' => $user->name,
                    'email' => $user->email,
                    'active' => $user->active,
                    'roles' => $assignments['roles']->map->displayName()->values()->all(),
                    'showrooms' => $assignments['showrooms']->pluck('name')->values()->all(),
                    'departments' => $assignments['departments']->map->displayName()->values()->all(),
                ],
                $assignments['showrooms']->modelKeys(),
            );

            return $user;
        });
    }

    /**
     * @param  array{name: string, email: string, lock_version: int, role_ids?: list<int>, showroom_ids?: list<int>, department_ids?: list<int>}  $data
     */
    public function update(User $editor, User $target, array $data): User
    {
        try {
            return $this->updateUser($editor, $target, $data);
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages(['email' => 'Сотрудник с таким email уже существует.']);
        }
    }

    /**
     * @param  array{name: string, email: string, lock_version: int, role_ids?: list<int>, showroom_ids?: list<int>, department_ids?: list<int>}  $data
     */
    private function updateUser(User $editor, User $target, array $data): User
    {
        return $this->administrators->protect(function () use ($editor, $target, $data) {
            /** @var User $user */
            $user = User::query()->lockForUpdate()->findOrFail($target->id);
            $user->ensureLockVersion($data['lock_version']);

            $before = $this->snapshot($user);

            $assignments = $this->resolveAssignments(
                $editor,
                $user,
                $data['role_ids'] ?? [],
                $data['showroom_ids'] ?? [],
                $data['department_ids'] ?? [],
            );

            $user->fill(['name' => $data['name'], 'email' => $data['email']]);

            if ($user->isDirty('email')) {
                $user->email_verified_at = now();
            }

            $user->bumpLockVersion()->save();

            $user->syncRoles($assignments['roles']);
            $user->showrooms()->sync($assignments['showrooms']->modelKeys());
            $user->departments()->sync($assignments['departments']->modelKeys());

            $this->recordUserChanges($user, $before, $this->snapshot($user->refresh()));

            return $user;
        }, 'role_ids');
    }

    public function setActive(User $editor, User $target, bool $active, mixed $lockVersion): User
    {
        if ($editor->is($target)) {
            throw ValidationException::withMessages(['active' => 'Нельзя изменить активность собственной учётной записи.']);
        }

        return $this->administrators->protect(function () use ($target, $active, $lockVersion) {
            /** @var User $user */
            $user = User::query()->lockForUpdate()->findOrFail($target->id);
            $user->ensureLockVersion($lockVersion);

            if ($user->active === $active) {
                return $user;
            }

            $user->active = $active;

            if (! $active) {
                $user->setRememberToken(Str::random(60));
            }

            $user->bumpLockVersion()->save();

            if (! $active) {
                $this->terminateSessions($user);
            }

            $this->audit->record(
                $active ? 'user.activated' : 'user.deactivated',
                'user',
                $user->id,
                $user->name,
                $active ? 'Сотрудник активирован' : 'Сотрудник отключён',
                ['active' => [! $active, $active]],
                $user->showrooms()->pluck('showrooms.id'),
            );

            return $user;
        });
    }

    public function setPassword(User $editor, User $target, string $password): void
    {
        if ($editor->is($target)) {
            throw ValidationException::withMessages(['password' => 'Свой пароль меняется в настройках безопасности.']);
        }

        DB::transaction(function () use ($target, $password) {
            /** @var User $user */
            $user = User::query()->lockForUpdate()->findOrFail($target->id);
            $user->forceFill([
                'password' => Hash::make($password),
                'remember_token' => Str::random(60),
            ])->save();

            $this->terminateSessions($user);

            $this->audit->record(
                'user.password_set',
                'user',
                $user->id,
                $user->name,
                'Установлен новый пароль',
                [],
                $user->showrooms()->pluck('showrooms.id'),
            );
        });
    }

    public function setGlobalAccess(User $editor, User $target, bool $granted, mixed $lockVersion): User
    {
        if (! $this->scope->canGrantGlobalAccess($editor)) {
            abort(403);
        }

        if ($editor->is($target)) {
            throw ValidationException::withMessages(['has_global_access' => 'Нельзя изменить собственный глобальный доступ.']);
        }

        return $this->administrators->protect(function () use ($target, $granted, $lockVersion) {
            /** @var User $user */
            $user = User::query()->lockForUpdate()->findOrFail($target->id);
            $user->ensureLockVersion($lockVersion);

            if ($user->has_global_access === $granted) {
                return $user;
            }

            $user->has_global_access = $granted;
            $user->bumpLockVersion()->save();

            $this->audit->record(
                $granted ? 'user.global_access_granted' : 'user.global_access_revoked',
                'user',
                $user->id,
                $user->name,
                $granted ? 'Выдан глобальный доступ ко всем салонам' : 'Отозван глобальный доступ ко всем салонам',
                ['has_global_access' => [! $granted, $granted]],
            );

            return $user;
        }, 'has_global_access');
    }

    /**
     * Validates every relation coming from the form against the editor's own scope.
     * Assignments outside the editor's scope are preserved untouched.
     *
     * @param  array<int, mixed>  $roleIds
     * @param  array<int, mixed>  $showroomIds
     * @param  array<int, mixed>  $departmentIds
     * @return array{roles: Collection<int, Role>, showrooms: Collection<int, Showroom>, departments: Collection<int, Department>}
     */
    private function resolveAssignments(User $editor, ?User $target, array $roleIds, array $showroomIds, array $departmentIds): array
    {
        $roleIds = $this->ids($roleIds);
        $showroomIds = $this->ids($showroomIds);
        $departmentIds = $this->ids($departmentIds);

        $editorScope = $this->scope->showroomIds($editor);
        $isGlobalEditor = $editorScope === null;

        $currentRoles = $target ? $target->roles()->get() : new Collection;
        $currentShowrooms = $target ? $target->showrooms()->get() : new Collection;
        $currentDepartments = $target ? $target->departments()->get() : new Collection;

        /** @var Collection<int, Role> $roles */
        $roles = Role::query()->with('permissions')->where('guard_name', 'web')->whereIn('id', $roleIds)->get();

        if ($roles->count() !== count($roleIds)) {
            throw ValidationException::withMessages(['role_ids' => 'Выбрана несуществующая роль.']);
        }

        foreach ($roles as $role) {
            $alreadyAssigned = $currentRoles->contains('id', $role->id);

            if (! $alreadyAssigned && ! $this->scope->canDelegateRole($editor, $role)) {
                throw ValidationException::withMessages([
                    'role_ids' => "Вы не можете назначить роль «{$role->displayName()}»: у вас нет всех её разрешений.",
                ]);
            }
        }

        /** @var Collection<int, Showroom> $requestedShowrooms */
        $requestedShowrooms = Showroom::query()->whereIn('id', $showroomIds)->get();

        if ($requestedShowrooms->count() !== count($showroomIds)) {
            throw ValidationException::withMessages(['showroom_ids' => 'Выбран несуществующий салон.']);
        }

        foreach ($requestedShowrooms as $showroom) {
            if (! $isGlobalEditor && ! in_array($showroom->id, $editorScope, true)) {
                throw ValidationException::withMessages(['showroom_ids' => 'Нельзя выдать доступ к салону вне вашей области.']);
            }

            if (! $showroom->active && ! $currentShowrooms->contains('id', $showroom->id)) {
                throw ValidationException::withMessages(['showroom_ids' => "Салон «{$showroom->name}» отключён."]);
            }
        }

        $preservedShowrooms = $isGlobalEditor
            ? new Collection
            : $currentShowrooms->reject(fn (Showroom $showroom) => in_array($showroom->id, $editorScope, true));

        $finalShowrooms = $requestedShowrooms->merge($preservedShowrooms)->unique('id')->values();

        if (! $isGlobalEditor && $requestedShowrooms->isEmpty()) {
            throw ValidationException::withMessages(['showroom_ids' => 'Выберите хотя бы один салон из вашей области.']);
        }

        /** @var Collection<int, Department> $requestedDepartments */
        $requestedDepartments = Department::query()->with('showroom')->whereIn('id', $departmentIds)->get();

        if ($requestedDepartments->count() !== count($departmentIds)) {
            throw ValidationException::withMessages(['department_ids' => 'Выбран несуществующий отдел.']);
        }

        $finalShowroomIds = $finalShowrooms->modelKeys();

        foreach ($requestedDepartments as $department) {
            if ($department->isShared()) {
                if (! $isGlobalEditor && ! $currentDepartments->contains('id', $department->id)) {
                    throw ValidationException::withMessages(['department_ids' => 'Назначать в общие отделы может только сотрудник с глобальным доступом.']);
                }
            } else {
                if (! $isGlobalEditor && ! in_array($department->showroom_id, $editorScope, true)) {
                    throw ValidationException::withMessages(['department_ids' => 'Нельзя назначить в отдел салона вне вашей области.']);
                }

                if (! in_array($department->showroom_id, $finalShowroomIds, true)) {
                    throw ValidationException::withMessages([
                        'department_ids' => "Отдел «{$department->displayName()}» относится к салону, к которому у сотрудника нет доступа.",
                    ]);
                }
            }

            if (! $department->active && ! $currentDepartments->contains('id', $department->id)) {
                throw ValidationException::withMessages(['department_ids' => "Отдел «{$department->name}» отключён."]);
            }
        }

        $preservedDepartments = $isGlobalEditor
            ? new Collection
            : $currentDepartments->filter(fn (Department $department) => $department->isShared()
                || ! in_array($department->showroom_id, $editorScope, true));

        $finalDepartments = $requestedDepartments->merge($preservedDepartments)->unique('id')->values();

        if ($target && $editor->is($target)) {
            $unchanged = $this->sameIds($roles, $currentRoles)
                && $this->sameIds($finalShowrooms, $currentShowrooms)
                && $this->sameIds($finalDepartments, $currentDepartments);

            if (! $unchanged) {
                throw ValidationException::withMessages(['role_ids' => 'Нельзя изменять собственные роли, салоны и отделы.']);
            }
        }

        return [
            'roles' => $roles,
            'showrooms' => $finalShowrooms,
            'departments' => $finalDepartments,
        ];
    }

    /**
     * @param  array<int, mixed>  $values
     * @return list<int>
     */
    private function ids(array $values): array
    {
        return array_values(array_unique(array_map('intval', $values)));
    }

    /**
     * @param  Collection<int, covariant \Illuminate\Database\Eloquent\Model>  $a
     * @param  Collection<int, covariant \Illuminate\Database\Eloquent\Model>  $b
     */
    private function sameIds(Collection $a, Collection $b): bool
    {
        $left = $a->modelKeys();
        $right = $b->modelKeys();
        sort($left);
        sort($right);

        return $left == $right;
    }

    /**
     * @return array{name: string, email: string, roles: array<int, string>, showrooms: array<int, string>, departments: array<int, string>}
     */
    private function snapshot(User $user): array
    {
        return [
            'name' => $user->name,
            'email' => $user->email,
            'roles' => $user->load('roles')->roles->mapWithKeys(fn (Role $role) => [$role->id => $role->displayName()])->all(),
            'showrooms' => $user->showrooms()->get()->mapWithKeys(fn (Showroom $showroom) => [$showroom->id => $showroom->name])->all(),
            'departments' => $user->departments()->with('showroom')->get()
                ->mapWithKeys(fn (Department $department) => [$department->id => $department->displayName()])->all(),
        ];
    }

    /**
     * @param  array{name: string, email: string, roles: array<int, string>, showrooms: array<int, string>, departments: array<int, string>}  $before
     * @param  array{name: string, email: string, roles: array<int, string>, showrooms: array<int, string>, departments: array<int, string>}  $after
     */
    private function recordUserChanges(User $user, array $before, array $after): void
    {
        $showroomIds = array_unique([...array_keys($before['showrooms']), ...array_keys($after['showrooms'])]);

        $profile = [];
        foreach (['name', 'email'] as $field) {
            if ($before[$field] !== $after[$field]) {
                $profile[$field] = [$before[$field], $after[$field]];
            }
        }

        if ($profile !== []) {
            $this->audit->record('user.updated', 'user', $user->id, $user->name, 'Изменены данные сотрудника', $profile, $showroomIds);
        }

        $sections = [
            'roles' => ['user.roles_changed', 'Изменены роли'],
            'showrooms' => ['user.showrooms_changed', 'Изменены доступные салоны'],
            'departments' => ['user.departments_changed', 'Изменено членство в отделах'],
        ];

        foreach ($sections as $key => [$event, $description]) {
            $added = array_values(array_diff_key($after[$key], $before[$key]));
            $removed = array_values(array_diff_key($before[$key], $after[$key]));

            if ($added === [] && $removed === []) {
                continue;
            }

            $this->audit->record($event, 'user', $user->id, $user->name, $description, [
                'added' => $added,
                'removed' => $removed,
            ], $showroomIds);
        }
    }

    private function terminateSessions(User $user): void
    {
        if (config('session.driver') === 'database') {
            DB::connection(config('session.connection'))
                ->table(config('session.table', 'sessions'))
                ->where('user_id', $user->id)
                ->delete();
        }
    }
}
