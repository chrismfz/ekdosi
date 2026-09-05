<?php

namespace App\Policies;

use Illuminate\Auth\Access\HandlesAuthorization;
use Illuminate\Foundation\Auth\User as AuthUser;

class CustomerUserPolicy
{
    use HandlesAuthorization;

    public function viewAny(AuthUser $authUser): bool
    {
        return $authUser->can('ViewAny:CustomerUser');
    }

    public function view(AuthUser $authUser): bool
    {
        return $authUser->can('View:CustomerUser');
    }

    public function create(AuthUser $authUser): bool
    {
        return $authUser->can('Create:CustomerUser');
    }

    public function update(AuthUser $authUser): bool
    {
        return $authUser->can('Update:CustomerUser');
    }

    public function delete(AuthUser $authUser): bool
    {
        return $authUser->can('Delete:CustomerUser');
    }

    public function deleteAny(AuthUser $authUser): bool
    {
        return $authUser->can('DeleteAny:CustomerUser');
    }

    public function restore(AuthUser $authUser): bool
    {
        return $authUser->can('Restore:CustomerUser');
    }

    public function forceDelete(AuthUser $authUser): bool
    {
        return $authUser->can('ForceDelete:CustomerUser');
    }

    public function forceDeleteAny(AuthUser $authUser): bool
    {
        return $authUser->can('ForceDeleteAny:CustomerUser');
    }

    public function restoreAny(AuthUser $authUser): bool
    {
        return $authUser->can('RestoreAny:CustomerUser');
    }

    public function replicate(AuthUser $authUser): bool
    {
        return $authUser->can('Replicate:CustomerUser');
    }

    public function reorder(AuthUser $authUser): bool
    {
        return $authUser->can('Reorder:CustomerUser');
    }
}
