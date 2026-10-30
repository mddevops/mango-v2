<?php

namespace App\Policies;

use App\Models\IntegrationConnection;
use App\Models\User;
use App\Services\AdministratorGuard;
use App\Support\PermissionCatalog as P;

/**
 * Connections hold credentials of external accounts, so a granted permission alone is
 * not enough: the user must also be a full administrator.
 */
class IntegrationConnectionPolicy
{
    public function __construct(private readonly AdministratorGuard $administrators) {}

    public function viewAny(User $user): bool
    {
        return $user->can(P::CONNECTIONS_MANAGE) && $this->administrators->isFullAdministrator($user);
    }

    public function create(User $user): bool
    {
        return $this->viewAny($user);
    }

    public function update(User $user, IntegrationConnection $connection): bool
    {
        return $this->viewAny($user);
    }

    public function manageSecrets(User $user): bool
    {
        return $this->viewAny($user) && $user->can(P::CONNECTIONS_SECRETS);
    }
}
