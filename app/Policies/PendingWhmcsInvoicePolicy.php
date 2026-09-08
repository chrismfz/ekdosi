<?php

declare(strict_types=1);

namespace App\Policies;

use Illuminate\Foundation\Auth\User as AuthUser;
use App\Models\PendingWhmcsInvoice;
use Illuminate\Auth\Access\HandlesAuthorization;

class PendingWhmcsInvoicePolicy
{
    use HandlesAuthorization;
    
    public function viewAny(AuthUser $authUser): bool
    {
        return $authUser->can('ViewAny:PendingWhmcsInvoice');
    }

    public function view(AuthUser $authUser, PendingWhmcsInvoice $pendingWhmcsInvoice): bool
    {
        return $authUser->can('View:PendingWhmcsInvoice');
    }

    public function create(AuthUser $authUser): bool
    {
        return $authUser->can('Create:PendingWhmcsInvoice');
    }

    public function update(AuthUser $authUser, PendingWhmcsInvoice $pendingWhmcsInvoice): bool
    {
        return $authUser->can('Update:PendingWhmcsInvoice');
    }

    public function delete(AuthUser $authUser, PendingWhmcsInvoice $pendingWhmcsInvoice): bool
    {
        return $authUser->can('Delete:PendingWhmcsInvoice');
    }

    public function deleteAny(AuthUser $authUser): bool
    {
        return $authUser->can('DeleteAny:PendingWhmcsInvoice');
    }

    public function restore(AuthUser $authUser, PendingWhmcsInvoice $pendingWhmcsInvoice): bool
    {
        return $authUser->can('Restore:PendingWhmcsInvoice');
    }

    public function forceDelete(AuthUser $authUser, PendingWhmcsInvoice $pendingWhmcsInvoice): bool
    {
        return $authUser->can('ForceDelete:PendingWhmcsInvoice');
    }

    public function forceDeleteAny(AuthUser $authUser): bool
    {
        return $authUser->can('ForceDeleteAny:PendingWhmcsInvoice');
    }

    public function restoreAny(AuthUser $authUser): bool
    {
        return $authUser->can('RestoreAny:PendingWhmcsInvoice');
    }

    public function replicate(AuthUser $authUser, PendingWhmcsInvoice $pendingWhmcsInvoice): bool
    {
        return $authUser->can('Replicate:PendingWhmcsInvoice');
    }

    public function reorder(AuthUser $authUser): bool
    {
        return $authUser->can('Reorder:PendingWhmcsInvoice');
    }

}