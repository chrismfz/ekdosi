<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\TicketBlockedSender;
use Illuminate\Auth\Access\HandlesAuthorization;
use Illuminate\Foundation\Auth\User as AuthUser;

class TicketBlockedSenderPolicy
{
    use HandlesAuthorization;

    public function viewAny(AuthUser $authUser): bool
    {
        return $authUser->can('ViewAny:TicketBlockedSender');
    }

    public function view(AuthUser $authUser, TicketBlockedSender $ticketBlockedSender): bool
    {
        return $authUser->can('View:TicketBlockedSender');
    }

    public function create(AuthUser $authUser): bool
    {
        return $authUser->can('Create:TicketBlockedSender');
    }

    public function update(AuthUser $authUser, TicketBlockedSender $ticketBlockedSender): bool
    {
        return $authUser->can('Update:TicketBlockedSender');
    }

    public function delete(AuthUser $authUser, TicketBlockedSender $ticketBlockedSender): bool
    {
        return $authUser->can('Delete:TicketBlockedSender');
    }

    public function deleteAny(AuthUser $authUser): bool
    {
        return $authUser->can('DeleteAny:TicketBlockedSender');
    }

    public function restore(AuthUser $authUser, TicketBlockedSender $ticketBlockedSender): bool
    {
        return $authUser->can('Restore:TicketBlockedSender');
    }

    public function forceDelete(AuthUser $authUser, TicketBlockedSender $ticketBlockedSender): bool
    {
        return $authUser->can('ForceDelete:TicketBlockedSender');
    }

    public function forceDeleteAny(AuthUser $authUser): bool
    {
        return $authUser->can('ForceDeleteAny:TicketBlockedSender');
    }

    public function restoreAny(AuthUser $authUser): bool
    {
        return $authUser->can('RestoreAny:TicketBlockedSender');
    }
}
