<?php

namespace App\Policies;

use App\Models\Source;
use App\Models\User;
use App\Services\AccessScope;
use App\Support\PermissionCatalog as P;

class SourcePolicy
{
    public function __construct(private readonly AccessScope $scope) {}

    public function viewAny(User $user): bool
    {
        return $user->can(P::SOURCES_VIEW);
    }

    public function view(User $user, Source $source): bool
    {
        return $user->can(P::SOURCES_VIEW) && $this->scope->canAccessShowroom($user, $source->showroom_id);
    }

    public function create(User $user): bool
    {
        return $user->can(P::SOURCES_MANAGE);
    }

    public function createIn(User $user, ?int $showroomId): bool
    {
        return $user->can(P::SOURCES_MANAGE) && $this->scope->canAccessShowroom($user, $showroomId);
    }

    public function update(User $user, Source $source): bool
    {
        return $this->createIn($user, $source->showroom_id);
    }
}
