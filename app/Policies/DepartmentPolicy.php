<?php

namespace App\Policies;

use App\Models\Department;
use App\Models\User;
use App\Services\AccessScope;
use App\Support\PermissionCatalog as P;

class DepartmentPolicy
{
    public function __construct(private readonly AccessScope $scope) {}

    public function viewAny(User $user): bool
    {
        return $user->can(P::DEPARTMENTS_VIEW);
    }

    public function view(User $user, Department $department): bool
    {
        return $user->can(P::DEPARTMENTS_VIEW)
            && ($department->isShared() || $this->scope->canAccessShowroom($user, $department->showroom_id));
    }

    public function create(User $user): bool
    {
        return $user->can(P::DEPARTMENTS_MANAGE);
    }

    /**
     * Shared departments belong to the whole organization and require global access.
     */
    public function createIn(User $user, ?int $showroomId): bool
    {
        if (! $user->can(P::DEPARTMENTS_MANAGE)) {
            return false;
        }

        return $showroomId === null
            ? $this->scope->isGlobal($user)
            : $this->scope->canAccessShowroom($user, $showroomId);
    }

    public function update(User $user, Department $department): bool
    {
        return $this->createIn($user, $department->showroom_id);
    }
}
