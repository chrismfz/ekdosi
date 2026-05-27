<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\PendingWhmcsInvoice;
use Illuminate\Auth\Access\HandlesAuthorization;
use Illuminate\Foundation\Auth\User as AuthUser;

/**
 * Authorization for the WHMCS Inbox (Stage B-2). Mirrors the same
 * shape as the other resource policies in the codebase
 * (CustomerPolicy / InvoicePolicy / etc.) so Filament Shield can
 * auto-generate the matching permissions:
 *   ViewAny:PendingWhmcsInvoice
 *   View:PendingWhmcsInvoice
 *   Update:PendingWhmcsInvoice    (drives the File-at-AADE action,
 *                                  the Reject / Hold / Re-stage
 *                                  actions, and any future bulk
 *                                  state mutations)
 *   Delete:PendingWhmcsInvoice     (hard-delete is the breakglass per
 *                                  the model docblock — no soft-deletes)
 *
 * Without a registered policy, Shield's default behaviour for
 * resources is engine-version-dependent — either deny-all (operator
 * 404 like the Καρτέλα page did in PRs #39-45) or allow-all
 * (readonly accountant role gets the File-at-AADE button by
 * accident). Both shapes are deploy-day surprises; this policy
 * closes the gap.
 *
 * Run `php artisan shield:generate --resource=WhmcsInboxResource`
 * after merging to actually create the permissions in the DB.
 */
class PendingWhmcsInvoicePolicy
{
    use HandlesAuthorization;

    public function viewAny(AuthUser $authUser): bool
    {
        return $authUser->can('ViewAny:PendingWhmcsInvoice');
    }

    public function view(AuthUser $authUser, PendingWhmcsInvoice $row): bool
    {
        return $authUser->can('View:PendingWhmcsInvoice');
    }

    /**
     * Update covers all state transitions: File at AADE, Reject,
     * Hold, Re-stage. There's no separate Filament action for
     * "create" since rows arrive via Stage B-1 ingestion paths only,
     * never through the panel.
     */
    public function update(AuthUser $authUser, PendingWhmcsInvoice $row): bool
    {
        return $authUser->can('Update:PendingWhmcsInvoice');
    }

    public function delete(AuthUser $authUser, PendingWhmcsInvoice $row): bool
    {
        return $authUser->can('Delete:PendingWhmcsInvoice');
    }

    public function deleteAny(AuthUser $authUser): bool
    {
        return $authUser->can('DeleteAny:PendingWhmcsInvoice');
    }

    public function forceDelete(AuthUser $authUser, PendingWhmcsInvoice $row): bool
    {
        return $authUser->can('ForceDelete:PendingWhmcsInvoice');
    }

    public function forceDeleteAny(AuthUser $authUser): bool
    {
        return $authUser->can('ForceDeleteAny:PendingWhmcsInvoice');
    }
}
