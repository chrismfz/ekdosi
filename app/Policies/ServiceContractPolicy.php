<?php

declare(strict_types=1);

namespace App\Policies;

use Illuminate\Foundation\Auth\User as AuthUser;
use App\Models\ServiceContract;
use Illuminate\Auth\Access\HandlesAuthorization;

class ServiceContractPolicy
{
    use HandlesAuthorization;
    
    public function viewAny(AuthUser $authUser): bool
    {
        return $authUser->can('ViewAny:ServiceContract');
    }

    public function view(AuthUser $authUser, ServiceContract $serviceContract): bool
    {
        return $authUser->can('View:ServiceContract');
    }

    public function create(AuthUser $authUser): bool
    {
        return $authUser->can('Create:ServiceContract');
    }

    public function update(AuthUser $authUser, ServiceContract $serviceContract): bool
    {
        return $authUser->can('Update:ServiceContract');
    }

    public function delete(AuthUser $authUser, ServiceContract $serviceContract): bool
    {
        return $authUser->can('Delete:ServiceContract');
    }

    public function deleteAny(AuthUser $authUser): bool
    {
        return $authUser->can('DeleteAny:ServiceContract');
    }

    public function restore(AuthUser $authUser, ServiceContract $serviceContract): bool
    {
        return $authUser->can('Restore:ServiceContract');
    }

    public function forceDelete(AuthUser $authUser, ServiceContract $serviceContract): bool
    {
        return $authUser->can('ForceDelete:ServiceContract');
    }

    public function forceDeleteAny(AuthUser $authUser): bool
    {
        return $authUser->can('ForceDeleteAny:ServiceContract');
    }

    public function restoreAny(AuthUser $authUser): bool
    {
        return $authUser->can('RestoreAny:ServiceContract');
    }

    public function replicate(AuthUser $authUser, ServiceContract $serviceContract): bool
    {
        return $authUser->can('Replicate:ServiceContract');
    }

    public function reorder(AuthUser $authUser): bool
    {
        return $authUser->can('Reorder:ServiceContract');
    }

}