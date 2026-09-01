<?php

declare(strict_types=1);

namespace App\Policies;

use Illuminate\Foundation\Auth\User as AuthUser;
use App\Models\UpdateRun;
use Illuminate\Auth\Access\HandlesAuthorization;

class UpdateRunPolicy
{
    use HandlesAuthorization;
    
    public function viewAny(AuthUser $authUser): bool
    {
        return $authUser->can('ViewAny:UpdateRun');
    }

    public function view(AuthUser $authUser, UpdateRun $updateRun): bool
    {
        return $authUser->can('View:UpdateRun');
    }

    public function create(AuthUser $authUser): bool
    {
        return $authUser->can('Create:UpdateRun');
    }

    public function update(AuthUser $authUser, UpdateRun $updateRun): bool
    {
        return $authUser->can('Update:UpdateRun');
    }

    public function delete(AuthUser $authUser, UpdateRun $updateRun): bool
    {
        return $authUser->can('Delete:UpdateRun');
    }

    public function deleteAny(AuthUser $authUser): bool
    {
        return $authUser->can('DeleteAny:UpdateRun');
    }

    public function restore(AuthUser $authUser, UpdateRun $updateRun): bool
    {
        return $authUser->can('Restore:UpdateRun');
    }

    public function forceDelete(AuthUser $authUser, UpdateRun $updateRun): bool
    {
        return $authUser->can('ForceDelete:UpdateRun');
    }

    public function forceDeleteAny(AuthUser $authUser): bool
    {
        return $authUser->can('ForceDeleteAny:UpdateRun');
    }

    public function restoreAny(AuthUser $authUser): bool
    {
        return $authUser->can('RestoreAny:UpdateRun');
    }

    public function replicate(AuthUser $authUser, UpdateRun $updateRun): bool
    {
        return $authUser->can('Replicate:UpdateRun');
    }

    public function reorder(AuthUser $authUser): bool
    {
        return $authUser->can('Reorder:UpdateRun');
    }

}