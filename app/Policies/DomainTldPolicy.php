<?php

declare(strict_types=1);

namespace App\Policies;

use Illuminate\Foundation\Auth\User as AuthUser;
use App\Models\DomainTld;
use Illuminate\Auth\Access\HandlesAuthorization;

class DomainTldPolicy
{
    use HandlesAuthorization;
    
    public function viewAny(AuthUser $authUser): bool
    {
        return $authUser->can('ViewAny:DomainTld');
    }

    public function view(AuthUser $authUser, DomainTld $domainTld): bool
    {
        return $authUser->can('View:DomainTld');
    }

    public function create(AuthUser $authUser): bool
    {
        return $authUser->can('Create:DomainTld');
    }

    public function update(AuthUser $authUser, DomainTld $domainTld): bool
    {
        return $authUser->can('Update:DomainTld');
    }

    public function delete(AuthUser $authUser, DomainTld $domainTld): bool
    {
        return $authUser->can('Delete:DomainTld');
    }

    public function deleteAny(AuthUser $authUser): bool
    {
        return $authUser->can('DeleteAny:DomainTld');
    }

    public function restore(AuthUser $authUser, DomainTld $domainTld): bool
    {
        return $authUser->can('Restore:DomainTld');
    }

    public function forceDelete(AuthUser $authUser, DomainTld $domainTld): bool
    {
        return $authUser->can('ForceDelete:DomainTld');
    }

    public function forceDeleteAny(AuthUser $authUser): bool
    {
        return $authUser->can('ForceDeleteAny:DomainTld');
    }

    public function restoreAny(AuthUser $authUser): bool
    {
        return $authUser->can('RestoreAny:DomainTld');
    }

    public function replicate(AuthUser $authUser, DomainTld $domainTld): bool
    {
        return $authUser->can('Replicate:DomainTld');
    }

    public function reorder(AuthUser $authUser): bool
    {
        return $authUser->can('Reorder:DomainTld');
    }

}