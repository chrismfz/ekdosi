<?php

declare(strict_types=1);

namespace App\Policies;

use Illuminate\Foundation\Auth\User as AuthUser;
use App\Models\TicketDepartment;
use Illuminate\Auth\Access\HandlesAuthorization;

class TicketDepartmentPolicy
{
    use HandlesAuthorization;
    
    public function viewAny(AuthUser $authUser): bool
    {
        return $authUser->can('ViewAny:TicketDepartment');
    }

    public function view(AuthUser $authUser, TicketDepartment $ticketDepartment): bool
    {
        return $authUser->can('View:TicketDepartment');
    }

    public function create(AuthUser $authUser): bool
    {
        return $authUser->can('Create:TicketDepartment');
    }

    public function update(AuthUser $authUser, TicketDepartment $ticketDepartment): bool
    {
        return $authUser->can('Update:TicketDepartment');
    }

    public function delete(AuthUser $authUser, TicketDepartment $ticketDepartment): bool
    {
        return $authUser->can('Delete:TicketDepartment');
    }

    public function deleteAny(AuthUser $authUser): bool
    {
        return $authUser->can('DeleteAny:TicketDepartment');
    }

    public function restore(AuthUser $authUser, TicketDepartment $ticketDepartment): bool
    {
        return $authUser->can('Restore:TicketDepartment');
    }

    public function forceDelete(AuthUser $authUser, TicketDepartment $ticketDepartment): bool
    {
        return $authUser->can('ForceDelete:TicketDepartment');
    }

    public function forceDeleteAny(AuthUser $authUser): bool
    {
        return $authUser->can('ForceDeleteAny:TicketDepartment');
    }

    public function restoreAny(AuthUser $authUser): bool
    {
        return $authUser->can('RestoreAny:TicketDepartment');
    }

    public function replicate(AuthUser $authUser, TicketDepartment $ticketDepartment): bool
    {
        return $authUser->can('Replicate:TicketDepartment');
    }

    public function reorder(AuthUser $authUser): bool
    {
        return $authUser->can('Reorder:TicketDepartment');
    }

}