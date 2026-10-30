<?php

namespace App\Services;

use App\Models\Role;
use App\Models\User;
use App\Support\RoleCatalog;
use Closure;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Keeps at least one effective administrator: an active account with the administrator
 * role and global access. Every change that could remove the last one runs through
 * protect(), which serializes such changes on the administrator role row.
 */
class AdministratorGuard
{
    /**
     * @template T
     *
     * @param  Closure(): T  $change
     * @return T
     */
    public function protect(Closure $change, string $errorKey = 'active'): mixed
    {
        return DB::transaction(function () use ($change, $errorKey) {
            // Concurrent requests block here until the first one commits or rolls back.
            Role::query()
                ->where('name', RoleCatalog::ADMINISTRATOR)
                ->lockForUpdate()
                ->first();

            $before = $this->count();

            $result = $change();

            if ($before > 0 && $this->count() === 0) {
                throw ValidationException::withMessages([
                    $errorKey => 'Нельзя отключить или лишить прав последнего активного администратора.',
                ]);
            }

            return $result;
        });
    }

    /**
     * Full administrator: the same definition as count() applied to one account. Used for
     * actions that a granted permission alone must not open, such as connection secrets.
     */
    public function isFullAdministrator(User $user): bool
    {
        return $user->active
            && $user->has_global_access
            && $user->roles()->where('name', RoleCatalog::ADMINISTRATOR)->exists();
    }

    public function count(): int
    {
        return User::query()
            ->where('active', true)
            ->where('has_global_access', true)
            ->whereHas('roles', fn ($roles) => $roles->where('name', RoleCatalog::ADMINISTRATOR))
            ->count();
    }
}
