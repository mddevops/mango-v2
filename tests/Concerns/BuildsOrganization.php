<?php

namespace Tests\Concerns;

use App\Models\Role;
use App\Models\Showroom;
use App\Models\User;
use App\Support\RoleCatalog;
use Database\Seeders\RolesAndPermissionsSeeder;

trait BuildsOrganization
{
    protected function seedRoles(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    protected function role(string $name): Role
    {
        return Role::query()->where('name', $name)->firstOrFail();
    }

    protected function administrator(array $attributes = []): User
    {
        $user = User::factory()->create(['has_global_access' => true, ...$attributes]);
        $user->assignRole(RoleCatalog::ADMINISTRATOR);

        return $user->fresh();
    }

    /**
     * @param  list<Showroom>  $showrooms
     */
    protected function employee(string|array $roles, array $showrooms = [], array $attributes = []): User
    {
        $user = User::factory()->create($attributes);
        $user->assignRole($roles);
        $user->showrooms()->sync(collect($showrooms)->pluck('id'));

        return $user->fresh();
    }

    /**
     * @return array<string, mixed>
     */
    protected function userPayload(array $overrides = []): array
    {
        return [
            'name' => 'Новый сотрудник',
            'email' => 'new.'.uniqid().'@example.com',
            'password' => 'Secret-Password-123',
            'password_confirmation' => 'Secret-Password-123',
            'active' => true,
            'role_ids' => [],
            'showroom_ids' => [],
            'department_ids' => [],
            ...$overrides,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function updatePayload(User $user, array $overrides = []): array
    {
        $user->refresh();

        return [
            'name' => $user->name,
            'email' => $user->email,
            'lock_version' => $user->lock_version,
            'role_ids' => $user->roles()->pluck('roles.id')->all(),
            'showroom_ids' => $user->showrooms()->pluck('showrooms.id')->all(),
            'department_ids' => $user->departments()->pluck('departments.id')->all(),
            ...$overrides,
        ];
    }
}
