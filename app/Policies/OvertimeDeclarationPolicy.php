<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\OvertimeDeclaration;
use Illuminate\Auth\Access\HandlesAuthorization;
use Illuminate\Foundation\Auth\User as AuthUser;

/** Shield-style policy (same shape shield:generate emits) — company_admin only by default. */
class OvertimeDeclarationPolicy
{
    use HandlesAuthorization;

    public function viewAny(AuthUser $authUser): bool
    {
        return $authUser->can('ViewAny:OvertimeDeclaration');
    }

    public function view(AuthUser $authUser, OvertimeDeclaration $overtimeDeclaration): bool
    {
        return $authUser->can('View:OvertimeDeclaration');
    }

    public function create(AuthUser $authUser): bool
    {
        return $authUser->can('Create:OvertimeDeclaration');
    }

    public function update(AuthUser $authUser, OvertimeDeclaration $overtimeDeclaration): bool
    {
        return $authUser->can('Update:OvertimeDeclaration');
    }

    public function delete(AuthUser $authUser, OvertimeDeclaration $overtimeDeclaration): bool
    {
        return $authUser->can('Delete:OvertimeDeclaration');
    }

    public function deleteAny(AuthUser $authUser): bool
    {
        return $authUser->can('DeleteAny:OvertimeDeclaration');
    }

    public function restore(AuthUser $authUser, OvertimeDeclaration $overtimeDeclaration): bool
    {
        return $authUser->can('Restore:OvertimeDeclaration');
    }

    public function forceDelete(AuthUser $authUser, OvertimeDeclaration $overtimeDeclaration): bool
    {
        return $authUser->can('ForceDelete:OvertimeDeclaration');
    }

    public function forceDeleteAny(AuthUser $authUser): bool
    {
        return $authUser->can('ForceDeleteAny:OvertimeDeclaration');
    }

    public function restoreAny(AuthUser $authUser): bool
    {
        return $authUser->can('RestoreAny:OvertimeDeclaration');
    }
}
