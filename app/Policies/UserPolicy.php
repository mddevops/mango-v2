<?php

namespace App\Policies;

use App\Models\User;
use App\Services\AccessScope;
use App\Support\PermissionCatalog as P;

class UserPolicy
{
    public function __construct(private readonly AccessScope $scope) {}

    public function viewAny(User $user): bool
    {
        return $user->can(P::USERS_VIEW);
    }

    public function view(User $user, User $target): bool
    {
        return $user->can(P::USERS_VIEW) && ($user->is($target) || $this->scope->canSeeUser($user, $target));
    }

    public function create(User $user): bool
    {
        return $user->can(P::USERS_MANAGE);
    }

    public function update(User $user, User $target): bool
    {
        return $this->scope->canManageUser($user, $target);
    }

    public function changeStatus(User $user, User $target): bool
    {
        return ! $user->is($target) && $this->scope->canManageUser($user, $target);
    }

    public function setPassword(User $user, User $target): bool
    {
        return ! $user->is($target)
            && $user->can(P::USERS_SET_PASSWORD)
            && $this->scope->canManageUser($user, $target);
    }

    public function grantGlobalAccess(User $user, User $target): bool
    {
        return ! $user->is($target)
            && $this->scope->canGrantGlobalAccess($user)
            && $this->scope->canManageUser($user, $target);
    }
}
