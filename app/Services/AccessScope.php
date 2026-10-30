<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\Department;
use App\Models\Order;
use App\Models\Role;
use App\Models\Showroom;
use App\Models\Source;
use App\Models\User;
use App\Support\PermissionCatalog;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Data scope of an employee. A permission says which action is allowed; the scope says
 * which showrooms, departments and employees the action may touch. Every query built
 * here constrains the scope first, so later search terms and OR conditions are always
 * nested inside it.
 */
class AccessScope
{
    public function isGlobal(User $user): bool
    {
        return $user->active && $user->has_global_access;
    }

    /**
     * @return list<int>|null null means every showroom
     */
    public function showroomIds(User $user): ?array
    {
        if ($this->isGlobal($user)) {
            return null;
        }

        return array_values($user->showrooms()
            ->pluck('showrooms.id')
            ->map(fn (int|string $id): int => (int) $id)
            ->all());
    }

    public function canAccessShowroom(User $user, ?int $showroomId): bool
    {
        if ($showroomId === null) {
            return false;
        }

        $ids = $this->showroomIds($user);

        return $ids === null || in_array($showroomId, $ids, true);
    }

    /**
     * @param  Builder<Showroom>  $query
     * @return Builder<Showroom>
     */
    public function showrooms(Builder $query, User $user): Builder
    {
        $ids = $this->showroomIds($user);

        return $ids === null ? $query : $query->whereIn('showrooms.id', $ids);
    }

    /**
     * Departments of accessible showrooms plus shared departments, which are common
     * for the whole organization.
     *
     * @param  Builder<Department>  $query
     * @return Builder<Department>
     */
    public function departments(Builder $query, User $user): Builder
    {
        $ids = $this->showroomIds($user);

        if ($ids === null) {
            return $query;
        }

        return $query->where(fn (Builder $scoped) => $scoped
            ->whereNull('departments.showroom_id')
            ->orWhereIn('departments.showroom_id', $ids));
    }

    /**
     * @param  Builder<Source>  $query
     * @return Builder<Source>
     */
    public function sources(Builder $query, User $user): Builder
    {
        $ids = $this->showroomIds($user);

        return $ids === null ? $query : $query->whereIn('sources.showroom_id', $ids);
    }

    /**
     * Orders of accessible showrooms; without `orders.view_all` only those where the
     * employee is responsible. The permission widens visibility inside the showroom
     * scope, it never adds showrooms. Unassigned orders need `orders.view_all`.
     *
     * @param  Builder<Order>  $query
     * @return Builder<Order>
     */
    public function orders(Builder $query, User $user): Builder
    {
        $ids = $this->showroomIds($user);

        return $query
            ->when($ids !== null, fn (Builder $scoped) => $scoped->whereIn('orders.showroom_id', $ids))
            ->when(! $user->can(PermissionCatalog::ORDERS_VIEW_ALL), fn (Builder $scoped) => $scoped->where('orders.responsible_id', $user->id));
    }

    public function canSeeOrder(User $user, Order $order): bool
    {
        return $this->canAccessShowroom($user, $order->showroom_id)
            && ($order->responsible_id === $user->id || $user->can(PermissionCatalog::ORDERS_VIEW_ALL));
    }

    /**
     * Employees that share at least one showroom with the viewer.
     *
     * @param  Builder<User>  $query
     * @return Builder<User>
     */
    public function users(Builder $query, User $viewer): Builder
    {
        $ids = $this->showroomIds($viewer);

        if ($ids === null) {
            return $query;
        }

        return $query->whereHas('showrooms', fn (Builder $showrooms) => $showrooms->whereIn('showrooms.id', $ids));
    }

    /**
     * An entry is visible only when every showroom it touches is accessible.
     * Entries without showrooms (roles, global access) are visible to global viewers only.
     *
     * @param  Builder<AuditLog>  $query
     * @return Builder<AuditLog>
     */
    public function auditLogs(Builder $query, User $viewer): Builder
    {
        $ids = $this->showroomIds($viewer);

        if ($ids === null) {
            return $query;
        }

        return $query
            ->whereHas('showrooms')
            ->whereDoesntHave('showrooms', fn (Builder $showrooms) => $showrooms->whereNotIn('showrooms.id', $ids));
    }

    public function canSeeUser(User $viewer, User $target): bool
    {
        return $this->users(User::query()->whereKey($target->id), $viewer)->exists();
    }

    /**
     * @return Collection<int, string>
     */
    public function permissionNames(User $user): Collection
    {
        return $user->getAllPermissions()->pluck('name')->values();
    }

    /**
     * A role can be delegated only by someone who already holds every permission of it.
     */
    public function canDelegateRole(User $editor, Role $role): bool
    {
        $own = $this->permissionNames($editor);

        return $role->permissions->pluck('name')->diff($own)->isEmpty();
    }

    /**
     * @param  iterable<string>  $permissions
     */
    public function canDelegatePermissions(User $editor, iterable $permissions): bool
    {
        return collect($permissions)->diff($this->permissionNames($editor))->isEmpty();
    }

    /**
     * Whether the editor may change another employee's data at all.
     */
    public function canManageUser(User $editor, User $target): bool
    {
        if (! $editor->can(PermissionCatalog::USERS_MANAGE)) {
            return false;
        }

        if ($editor->is($target)) {
            return true;
        }

        if (! $this->canSeeUser($editor, $target)) {
            return false;
        }

        if ($target->has_global_access && ! $this->isGlobal($editor)) {
            return false;
        }

        return $target->roles->every(fn (Role $role) => $this->canDelegateRole($editor, $role));
    }

    public function canGrantGlobalAccess(User $editor): bool
    {
        return $this->isGlobal($editor) && $editor->can(PermissionCatalog::ACCESS_GRANT_GLOBAL);
    }
}
