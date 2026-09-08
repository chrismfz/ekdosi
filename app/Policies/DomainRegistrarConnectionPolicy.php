<?php

declare(strict_types=1);

namespace App\Policies;

use Illuminate\Foundation\Auth\User as AuthUser;
use App\Models\DomainRegistrarConnection;
use Illuminate\Auth\Access\HandlesAuthorization;

class DomainRegistrarConnectionPolicy
{
    use HandlesAuthorization;
    
    public function viewAny(AuthUser $authUser): bool
    {
        return $authUser->can('ViewAny:DomainRegistrarConnection');
    }

    public function view(AuthUser $authUser, DomainRegistrarConnection $domainRegistrarConnection): bool
    {
        return $authUser->can('View:DomainRegistrarConnection');
    }

    public function create(AuthUser $authUser): bool
    {
        return $authUser->can('Create:DomainRegistrarConnection');
    }

    public function update(AuthUser $authUser, DomainRegistrarConnection $domainRegistrarConnection): bool
    {
        return $authUser->can('Update:DomainRegistrarConnection');
    }

    public function delete(AuthUser $authUser, DomainRegistrarConnection $domainRegistrarConnection): bool
    {
        return $authUser->can('Delete:DomainRegistrarConnection');
    }

    public function deleteAny(AuthUser $authUser): bool
    {
        return $authUser->can('DeleteAny:DomainRegistrarConnection');
    }

    public function restore(AuthUser $authUser, DomainRegistrarConnection $domainRegistrarConnection): bool
    {
        return $authUser->can('Restore:DomainRegistrarConnection');
    }

    public function forceDelete(AuthUser $authUser, DomainRegistrarConnection $domainRegistrarConnection): bool
    {
        return $authUser->can('ForceDelete:DomainRegistrarConnection');
    }

    public function forceDeleteAny(AuthUser $authUser): bool
    {
        return $authUser->can('ForceDeleteAny:DomainRegistrarConnection');
    }

    public function restoreAny(AuthUser $authUser): bool
    {
        return $authUser->can('RestoreAny:DomainRegistrarConnection');
    }

    public function replicate(AuthUser $authUser, DomainRegistrarConnection $domainRegistrarConnection): bool
    {
        return $authUser->can('Replicate:DomainRegistrarConnection');
    }

    public function reorder(AuthUser $authUser): bool
    {
        return $authUser->can('Reorder:DomainRegistrarConnection');
    }

}