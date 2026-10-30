<?php

namespace App\Policies;

use App\Models\Role;
use App\Models\User;
use App\Support\PermissionCatalog as P;

class RolePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can(P::ROLES_MANAGE);
    }

    public function create(User $user): bool
    {
        return $user->can(P::ROLES_MANAGE);
    }

    public function update(User $user, Role $role): bool
    {
        return $user->can(P::ROLES_MANAGE);
    }

    public function delete(User $user, Role $role): bool
    {
        return $user->can(P::ROLES_MANAGE) && ! $role->is_system && ! $role->isAdministrator();
    }
}
