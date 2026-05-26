<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Invoice;
use Illuminate\Auth\Access\HandlesAuthorization;
use Illuminate\Foundation\Auth\User as AuthUser;

/**
 * Policy generated from the CustomerPolicy template — Shield's seeder
 * couldn't run against the dev sandbox (MariaDB not running locally),
 * but the policy file must exist on disk so the Resource's authorize()
 * checks resolve. Permission rows are inserted at install time by the
 * seeder's shield:generate hook.
 *
 * NOTE: the InvoiceResource is READ-ONLY at this stage. Update / Create /
 * Delete abilities are declared here for forward compatibility (PR #7
 * MyDataSubmitter and PR #8 IssueInvoice will need them) but the UI
 * doesn't expose those actions yet.
 */
class InvoicePolicy
{
    use HandlesAuthorization;

    public function viewAny(AuthUser $authUser): bool
    {
        return $authUser->can('ViewAny:Invoice');
    }

    public function view(AuthUser $authUser, Invoice $invoice): bool
    {
        return $authUser->can('View:Invoice');
    }

    public function create(AuthUser $authUser): bool
    {
        return $authUser->can('Create:Invoice');
    }

    public function update(AuthUser $authUser, Invoice $invoice): bool
    {
        return $authUser->can('Update:Invoice');
    }

    public function delete(AuthUser $authUser, Invoice $invoice): bool
    {
        return $authUser->can('Delete:Invoice');
    }

    public function deleteAny(AuthUser $authUser): bool
    {
        return $authUser->can('DeleteAny:Invoice');
    }

    public function restore(AuthUser $authUser, Invoice $invoice): bool
    {
        return $authUser->can('Restore:Invoice');
    }

    public function forceDelete(AuthUser $authUser, Invoice $invoice): bool
    {
        return $authUser->can('ForceDelete:Invoice');
    }

    public function forceDeleteAny(AuthUser $authUser): bool
    {
        return $authUser->can('ForceDeleteAny:Invoice');
    }

    public function restoreAny(AuthUser $authUser): bool
    {
        return $authUser->can('RestoreAny:Invoice');
    }

    public function replicate(AuthUser $authUser, Invoice $invoice): bool
    {
        return $authUser->can('Replicate:Invoice');
    }

    public function reorder(AuthUser $authUser): bool
    {
        return $authUser->can('Reorder:Invoice');
    }
}
