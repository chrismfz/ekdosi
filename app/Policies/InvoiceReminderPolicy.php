<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\InvoiceReminder;
use Illuminate\Auth\Access\HandlesAuthorization;
use Illuminate\Foundation\Auth\User as AuthUser;

class InvoiceReminderPolicy
{
    use HandlesAuthorization;

    public function viewAny(AuthUser $authUser): bool
    {
        return $authUser->can('ViewAny:InvoiceReminder');
    }

    public function view(AuthUser $authUser, InvoiceReminder $invoiceReminder): bool
    {
        return $authUser->can('View:InvoiceReminder');
    }

    public function create(AuthUser $authUser): bool
    {
        return $authUser->can('Create:InvoiceReminder');
    }

    public function update(AuthUser $authUser, InvoiceReminder $invoiceReminder): bool
    {
        return $authUser->can('Update:InvoiceReminder');
    }

    public function delete(AuthUser $authUser, InvoiceReminder $invoiceReminder): bool
    {
        return $authUser->can('Delete:InvoiceReminder');
    }

    public function deleteAny(AuthUser $authUser): bool
    {
        return $authUser->can('DeleteAny:InvoiceReminder');
    }

    public function restore(AuthUser $authUser, InvoiceReminder $invoiceReminder): bool
    {
        return $authUser->can('Restore:InvoiceReminder');
    }

    public function forceDelete(AuthUser $authUser, InvoiceReminder $invoiceReminder): bool
    {
        return $authUser->can('ForceDelete:InvoiceReminder');
    }

    public function forceDeleteAny(AuthUser $authUser): bool
    {
        return $authUser->can('ForceDeleteAny:InvoiceReminder');
    }

    public function restoreAny(AuthUser $authUser): bool
    {
        return $authUser->can('RestoreAny:InvoiceReminder');
    }

    public function replicate(AuthUser $authUser, InvoiceReminder $invoiceReminder): bool
    {
        return $authUser->can('Replicate:InvoiceReminder');
    }

    public function reorder(AuthUser $authUser): bool
    {
        return $authUser->can('Reorder:InvoiceReminder');
    }
}
