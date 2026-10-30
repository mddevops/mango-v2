<?php

namespace App\Policies;

use App\Models\Order;
use App\Models\User;
use App\Services\AccessScope;
use App\Support\PermissionCatalog as P;

/**
 * Every order ability needs the order in the employee's order scope (AccessScope::orders):
 * an accessible showroom and, without `orders.view_all`, being its responsible. Each action
 * is a separate permission: history, previous orders and the cross-showroom view are
 * not implied by viewing or editing. Audit entries are written regardless of who may
 * read them.
 */
class OrderPolicy
{
    public function __construct(private readonly AccessScope $scope) {}

    public function viewAny(User $user): bool
    {
        return $user->can(P::ORDERS_VIEW);
    }

    public function view(User $user, Order $order): bool
    {
        return $user->can(P::ORDERS_VIEW) && $this->scope->canSeeOrder($user, $order);
    }

    public function create(User $user): bool
    {
        return $user->can(P::ORDERS_VIEW) && $user->can(P::ORDERS_CREATE);
    }

    public function update(User $user, Order $order): bool
    {
        return $user->can(P::ORDERS_UPDATE) && $this->view($user, $order);
    }

    public function assign(User $user, Order $order): bool
    {
        return self::canAssign($user) && $this->update($user, $order);
    }

    /**
     * Choosing a responsible: from the order's showroom with `orders.assign`, from every
     * showroom with `orders.assign_any`. Without either the creator becomes responsible.
     */
    public static function canAssign(User $user): bool
    {
        return $user->can(P::ORDERS_ASSIGN) || $user->can(P::ORDERS_ASSIGN_ANY);
    }

    public function comment(User $user, Order $order): bool
    {
        return $user->can(P::ORDERS_COMMENT) && $this->view($user, $order);
    }

    public function viewHistory(User $user, Order $order): bool
    {
        return $user->can(P::ORDERS_HISTORY) && $this->view($user, $order);
    }

    /**
     * Recordings of the order's calls: their own permission on top of the history
     * (calls are shown in it). Nothing else grants it — not the connection settings.
     */
    public function listenRecordings(User $user, Order $order): bool
    {
        return $user->can(P::ORDERS_RECORDINGS) && $this->viewHistory($user, $order);
    }

    public function viewPrevious(User $user, Order $order): bool
    {
        return $user->can(P::ORDERS_PREVIOUS) && $this->view($user, $order);
    }

    /**
     * Handing an order to callback distribution: the action permission, `orders.view_all`
     * (unassigned orders are visible only with it) and the order itself in scope.
     */
    public function distribute(User $user, Order $order): bool
    {
        return $user->can('distribution-run') && $this->view($user, $order);
    }

    /**
     * Taking a visible unassigned order: editing orders is enough, the employee becomes
     * the responsible himself.
     */
    public function take(User $user, Order $order): bool
    {
        return $order->responsible_id === null && $this->update($user, $order);
    }

    /**
     * A transfer edits the source order and creates an order in the receiving showroom;
     * access to the receiving showroom is checked with the chosen showroom.
     */
    public function transfer(User $user, Order $order): bool
    {
        return $user->can(P::ORDERS_TRANSFER) && $this->update($user, $order) && $this->create($user);
    }

    public function viewCrossShowroom(User $user, Order $order): bool
    {
        return $user->can(P::ORDERS_CROSS_SHOWROOM) && $this->viewPrevious($user, $order);
    }
}
