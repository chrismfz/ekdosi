<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\InvoiceMailLog;
use Illuminate\Auth\Access\HandlesAuthorization;
use Illuminate\Foundation\Auth\User as AuthUser;

class InvoiceMailLogPolicy
{
    use HandlesAuthorization;

    public function viewAny(AuthUser $authUser): bool
    {
        return $authUser->can('ViewAny:InvoiceMailLog');
    }

    public function view(AuthUser $authUser, InvoiceMailLog $invoiceMailLog): bool
    {
        return $authUser->can('View:InvoiceMailLog');
    }

    public function create(AuthUser $authUser): bool
    {
        return $authUser->can('Create:InvoiceMailLog');
    }

    public function update(AuthUser $authUser, InvoiceMailLog $invoiceMailLog): bool
    {
        return $authUser->can('Update:InvoiceMailLog');
    }

    public function delete(AuthUser $authUser, InvoiceMailLog $invoiceMailLog): bool
    {
        return $authUser->can('Delete:InvoiceMailLog');
    }

    public function deleteAny(AuthUser $authUser): bool
    {
        return $authUser->can('DeleteAny:InvoiceMailLog');
    }

    public function restore(AuthUser $authUser, InvoiceMailLog $invoiceMailLog): bool
    {
        return $authUser->can('Restore:InvoiceMailLog');
    }

    public function forceDelete(AuthUser $authUser, InvoiceMailLog $invoiceMailLog): bool
    {
        return $authUser->can('ForceDelete:InvoiceMailLog');
    }

    public function forceDeleteAny(AuthUser $authUser): bool
    {
        return $authUser->can('ForceDeleteAny:InvoiceMailLog');
    }

    public function restoreAny(AuthUser $authUser): bool
    {
        return $authUser->can('RestoreAny:InvoiceMailLog');
    }

    public function replicate(AuthUser $authUser, InvoiceMailLog $invoiceMailLog): bool
    {
        return $authUser->can('Replicate:InvoiceMailLog');
    }

    public function reorder(AuthUser $authUser): bool
    {
        return $authUser->can('Reorder:InvoiceMailLog');
    }
}
