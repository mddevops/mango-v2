<?php

namespace App\Policies;

use App\Models\Showroom;
use App\Models\User;
use App\Services\AccessScope;
use App\Support\PermissionCatalog as P;

class ShowroomPolicy
{
    public function __construct(private readonly AccessScope $scope) {}

    public function viewAny(User $user): bool
    {
        return $user->can(P::SHOWROOMS_VIEW);
    }

    public function view(User $user, Showroom $showroom): bool
    {
        return $user->can(P::SHOWROOMS_VIEW) && $this->scope->canAccessShowroom($user, $showroom->id);
    }

    /**
     * A new showroom is outside every personal scope, so only global access may create it.
     */
    public function create(User $user): bool
    {
        return $user->can(P::SHOWROOMS_MANAGE) && $this->scope->isGlobal($user);
    }

    public function update(User $user, Showroom $showroom): bool
    {
        return $user->can(P::SHOWROOMS_MANAGE) && $this->scope->canAccessShowroom($user, $showroom->id);
    }
}
