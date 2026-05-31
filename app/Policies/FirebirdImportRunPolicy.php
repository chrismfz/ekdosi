<?php

declare(strict_types=1);

namespace App\Policies;

use Illuminate\Foundation\Auth\User as AuthUser;
use App\Models\FirebirdImportRun;
use Illuminate\Auth\Access\HandlesAuthorization;

class FirebirdImportRunPolicy
{
    use HandlesAuthorization;
    
    public function viewAny(AuthUser $authUser): bool
    {
        return $authUser->can('ViewAny:FirebirdImportRun');
    }

    public function view(AuthUser $authUser, FirebirdImportRun $firebirdImportRun): bool
    {
        return $authUser->can('View:FirebirdImportRun');
    }

    public function create(AuthUser $authUser): bool
    {
        return $authUser->can('Create:FirebirdImportRun');
    }

    public function update(AuthUser $authUser, FirebirdImportRun $firebirdImportRun): bool
    {
        return $authUser->can('Update:FirebirdImportRun');
    }

    public function delete(AuthUser $authUser, FirebirdImportRun $firebirdImportRun): bool
    {
        return $authUser->can('Delete:FirebirdImportRun');
    }

    public function deleteAny(AuthUser $authUser): bool
    {
        return $authUser->can('DeleteAny:FirebirdImportRun');
    }

    public function restore(AuthUser $authUser, FirebirdImportRun $firebirdImportRun): bool
    {
        return $authUser->can('Restore:FirebirdImportRun');
    }

    public function forceDelete(AuthUser $authUser, FirebirdImportRun $firebirdImportRun): bool
    {
        return $authUser->can('ForceDelete:FirebirdImportRun');
    }

    public function forceDeleteAny(AuthUser $authUser): bool
    {
        return $authUser->can('ForceDeleteAny:FirebirdImportRun');
    }

    public function restoreAny(AuthUser $authUser): bool
    {
        return $authUser->can('RestoreAny:FirebirdImportRun');
    }

    public function replicate(AuthUser $authUser, FirebirdImportRun $firebirdImportRun): bool
    {
        return $authUser->can('Replicate:FirebirdImportRun');
    }

    public function reorder(AuthUser $authUser): bool
    {
        return $authUser->can('Reorder:FirebirdImportRun');
    }

}