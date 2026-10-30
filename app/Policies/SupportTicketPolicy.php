<?php

namespace App\Policies;

use App\Models\SupportTicket;
use App\Models\User;
use App\Support\PermissionCatalog as P;

/**
 * Every employee may write to support and see their own requests; the support permission
 * opens every request for answering instead of writing new ones. Deleting hides the request
 * for everyone.
 */
class SupportTicketPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function create(User $user): bool
    {
        return ! $user->can(P::SUPPORT_MANAGE);
    }

    public function view(User $user, SupportTicket $ticket): bool
    {
        return $ticket->isAuthor($user) || $user->can(P::SUPPORT_MANAGE);
    }

    public function reply(User $user, SupportTicket $ticket): bool
    {
        return $this->view($user, $ticket);
    }

    public function delete(User $user, SupportTicket $ticket): bool
    {
        return $this->view($user, $ticket);
    }
}
