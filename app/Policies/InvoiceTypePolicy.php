<?php

declare(strict_types=1);

namespace App\Policies;

use Illuminate\Foundation\Auth\User as AuthUser;
use App\Models\InvoiceType;
use Illuminate\Auth\Access\HandlesAuthorization;

class InvoiceTypePolicy
{
    use HandlesAuthorization;
    
    public function viewAny(AuthUser $authUser): bool
    {
        return $authUser->can('ViewAny:InvoiceType');
    }

    public function view(AuthUser $authUser, InvoiceType $invoiceType): bool
    {
        return $authUser->can('View:InvoiceType');
    }

    public function create(AuthUser $authUser): bool
    {
        return $authUser->can('Create:InvoiceType');
    }

    public function update(AuthUser $authUser, InvoiceType $invoiceType): bool
    {
        return $authUser->can('Update:InvoiceType');
    }

    public function delete(AuthUser $authUser, InvoiceType $invoiceType): bool
    {
        return $authUser->can('Delete:InvoiceType');
    }

    public function deleteAny(AuthUser $authUser): bool
    {
        return $authUser->can('DeleteAny:InvoiceType');
    }

    public function restore(AuthUser $authUser, InvoiceType $invoiceType): bool
    {
        return $authUser->can('Restore:InvoiceType');
    }

    public function forceDelete(AuthUser $authUser, InvoiceType $invoiceType): bool
    {
        return $authUser->can('ForceDelete:InvoiceType');
    }

    public function forceDeleteAny(AuthUser $authUser): bool
    {
        return $authUser->can('ForceDeleteAny:InvoiceType');
    }

    public function restoreAny(AuthUser $authUser): bool
    {
        return $authUser->can('RestoreAny:InvoiceType');
    }

    public function replicate(AuthUser $authUser, InvoiceType $invoiceType): bool
    {
        return $authUser->can('Replicate:InvoiceType');
    }

    public function reorder(AuthUser $authUser): bool
    {
        return $authUser->can('Reorder:InvoiceType');
    }

}