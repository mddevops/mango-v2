<?php

namespace App\Policies;

use App\Models\User;
use App\Support\PermissionCatalog as P;

class AuditLogPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can(P::AUDIT_VIEW);
    }
}
