<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\CannedReply;
use Illuminate\Auth\Access\HandlesAuthorization;
use Illuminate\Foundation\Auth\User as AuthUser;

class CannedReplyPolicy
{
    use HandlesAuthorization;

    public function viewAny(AuthUser $authUser): bool
    {
        return $authUser->can('ViewAny:CannedReply');
    }

    public function view(AuthUser $authUser, CannedReply $cannedReply): bool
    {
        return $authUser->can('View:CannedReply');
    }

    public function create(AuthUser $authUser): bool
    {
        return $authUser->can('Create:CannedReply');
    }

    public function update(AuthUser $authUser, CannedReply $cannedReply): bool
    {
        return $authUser->can('Update:CannedReply');
    }

    public function delete(AuthUser $authUser, CannedReply $cannedReply): bool
    {
        return $authUser->can('Delete:CannedReply');
    }

    public function deleteAny(AuthUser $authUser): bool
    {
        return $authUser->can('DeleteAny:CannedReply');
    }

    public function restore(AuthUser $authUser, CannedReply $cannedReply): bool
    {
        return $authUser->can('Restore:CannedReply');
    }

    public function forceDelete(AuthUser $authUser, CannedReply $cannedReply): bool
    {
        return $authUser->can('ForceDelete:CannedReply');
    }

    public function forceDeleteAny(AuthUser $authUser): bool
    {
        return $authUser->can('ForceDeleteAny:CannedReply');
    }

    public function restoreAny(AuthUser $authUser): bool
    {
        return $authUser->can('RestoreAny:CannedReply');
    }

    public function replicate(AuthUser $authUser, CannedReply $cannedReply): bool
    {
        return $authUser->can('Replicate:CannedReply');
    }

    public function reorder(AuthUser $authUser): bool
    {
        return $authUser->can('Reorder:CannedReply');
    }
}
