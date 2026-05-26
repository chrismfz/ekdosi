<?php

declare(strict_types=1);

namespace App\Policies;

use Illuminate\Foundation\Auth\User as AuthUser;
use App\Models\DistributionAim;
use Illuminate\Auth\Access\HandlesAuthorization;

class DistributionAimPolicy
{
    use HandlesAuthorization;
    
    public function viewAny(AuthUser $authUser): bool
    {
        return $authUser->can('ViewAny:DistributionAim');
    }

    public function view(AuthUser $authUser, DistributionAim $distributionAim): bool
    {
        return $authUser->can('View:DistributionAim');
    }

    public function create(AuthUser $authUser): bool
    {
        return $authUser->can('Create:DistributionAim');
    }

    public function update(AuthUser $authUser, DistributionAim $distributionAim): bool
    {
        return $authUser->can('Update:DistributionAim');
    }

    public function delete(AuthUser $authUser, DistributionAim $distributionAim): bool
    {
        return $authUser->can('Delete:DistributionAim');
    }

    public function deleteAny(AuthUser $authUser): bool
    {
        return $authUser->can('DeleteAny:DistributionAim');
    }

    public function restore(AuthUser $authUser, DistributionAim $distributionAim): bool
    {
        return $authUser->can('Restore:DistributionAim');
    }

    public function forceDelete(AuthUser $authUser, DistributionAim $distributionAim): bool
    {
        return $authUser->can('ForceDelete:DistributionAim');
    }

    public function forceDeleteAny(AuthUser $authUser): bool
    {
        return $authUser->can('ForceDeleteAny:DistributionAim');
    }

    public function restoreAny(AuthUser $authUser): bool
    {
        return $authUser->can('RestoreAny:DistributionAim');
    }

    public function replicate(AuthUser $authUser, DistributionAim $distributionAim): bool
    {
        return $authUser->can('Replicate:DistributionAim');
    }

    public function reorder(AuthUser $authUser): bool
    {
        return $authUser->can('Reorder:DistributionAim');
    }

}