<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Quote;
use Illuminate\Auth\Access\HandlesAuthorization;
use Illuminate\Foundation\Auth\User as AuthUser;

/**
 * Authorization for Προσφορές (Quotes). Same shape as the other resource
 * policies (InvoicePolicy / PendingWhmcsInvoicePolicy) so Filament Shield's
 * generated permissions drive access:
 *   ViewAny:Quote / View:Quote / Create:Quote / Update:Quote / …
 *
 * `Quote` is in TenantRoleProvisioner::OPERATOR_PERMISSION_MAP, so the operator role
 * gets ViewAny/View/Create/Update:Quote; company_admin (all perms) and
 * super_admin (Gate::before bypass) see everything. Replaces the previous
 * blanket `canAccess() => auth()->check()` bypass on QuoteResource.
 */
class QuotePolicy
{
    use HandlesAuthorization;

    public function viewAny(AuthUser $authUser): bool
    {
        return $authUser->can('ViewAny:Quote');
    }

    public function view(AuthUser $authUser, Quote $quote): bool
    {
        return $authUser->can('View:Quote');
    }

    public function create(AuthUser $authUser): bool
    {
        return $authUser->can('Create:Quote');
    }

    public function update(AuthUser $authUser, Quote $quote): bool
    {
        return $authUser->can('Update:Quote');
    }

    public function delete(AuthUser $authUser, Quote $quote): bool
    {
        return $authUser->can('Delete:Quote');
    }

    public function deleteAny(AuthUser $authUser): bool
    {
        return $authUser->can('DeleteAny:Quote');
    }

    public function restore(AuthUser $authUser, Quote $quote): bool
    {
        return $authUser->can('Restore:Quote');
    }

    public function forceDelete(AuthUser $authUser, Quote $quote): bool
    {
        return $authUser->can('ForceDelete:Quote');
    }

    public function forceDeleteAny(AuthUser $authUser): bool
    {
        return $authUser->can('ForceDeleteAny:Quote');
    }

    public function restoreAny(AuthUser $authUser): bool
    {
        return $authUser->can('RestoreAny:Quote');
    }

    public function replicate(AuthUser $authUser, Quote $quote): bool
    {
        return $authUser->can('Replicate:Quote');
    }

    public function reorder(AuthUser $authUser): bool
    {
        return $authUser->can('Reorder:Quote');
    }
}
