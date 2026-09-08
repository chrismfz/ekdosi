<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\CmrNote;
use Illuminate\Auth\Access\HandlesAuthorization;
use Illuminate\Foundation\Auth\User as AuthUser;

class CmrNotePolicy
{
    use HandlesAuthorization;

    public function viewAny(AuthUser $authUser): bool
    {
        return $authUser->can('ViewAny:CmrNote');
    }

    public function view(AuthUser $authUser, CmrNote $cmrNote): bool
    {
        return $authUser->can('View:CmrNote');
    }

    public function create(AuthUser $authUser): bool
    {
        return $authUser->can('Create:CmrNote');
    }

    public function update(AuthUser $authUser, CmrNote $cmrNote): bool
    {
        return $authUser->can('Update:CmrNote');
    }

    public function delete(AuthUser $authUser, CmrNote $cmrNote): bool
    {
        return $authUser->can('Delete:CmrNote');
    }

    public function deleteAny(AuthUser $authUser): bool
    {
        return $authUser->can('DeleteAny:CmrNote');
    }

    public function restore(AuthUser $authUser, CmrNote $cmrNote): bool
    {
        return $authUser->can('Restore:CmrNote');
    }

    public function forceDelete(AuthUser $authUser, CmrNote $cmrNote): bool
    {
        return $authUser->can('ForceDelete:CmrNote');
    }

    public function forceDeleteAny(AuthUser $authUser): bool
    {
        return $authUser->can('ForceDeleteAny:CmrNote');
    }

    public function restoreAny(AuthUser $authUser): bool
    {
        return $authUser->can('RestoreAny:CmrNote');
    }

    public function replicate(AuthUser $authUser, CmrNote $cmrNote): bool
    {
        return $authUser->can('Replicate:CmrNote');
    }

    public function reorder(AuthUser $authUser): bool
    {
        return $authUser->can('Reorder:CmrNote');
    }
}
