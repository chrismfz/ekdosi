<?php

declare(strict_types=1);

namespace App\Policies;

use Illuminate\Foundation\Auth\User as AuthUser;
use App\Models\MetricUnit;
use Illuminate\Auth\Access\HandlesAuthorization;

class MetricUnitPolicy
{
    use HandlesAuthorization;
    
    public function viewAny(AuthUser $authUser): bool
    {
        return $authUser->can('ViewAny:MetricUnit');
    }

    public function view(AuthUser $authUser, MetricUnit $metricUnit): bool
    {
        return $authUser->can('View:MetricUnit');
    }

    public function create(AuthUser $authUser): bool
    {
        return $authUser->can('Create:MetricUnit');
    }

    public function update(AuthUser $authUser, MetricUnit $metricUnit): bool
    {
        return $authUser->can('Update:MetricUnit');
    }

    public function delete(AuthUser $authUser, MetricUnit $metricUnit): bool
    {
        return $authUser->can('Delete:MetricUnit');
    }

    public function deleteAny(AuthUser $authUser): bool
    {
        return $authUser->can('DeleteAny:MetricUnit');
    }

    public function restore(AuthUser $authUser, MetricUnit $metricUnit): bool
    {
        return $authUser->can('Restore:MetricUnit');
    }

    public function forceDelete(AuthUser $authUser, MetricUnit $metricUnit): bool
    {
        return $authUser->can('ForceDelete:MetricUnit');
    }

    public function forceDeleteAny(AuthUser $authUser): bool
    {
        return $authUser->can('ForceDeleteAny:MetricUnit');
    }

    public function restoreAny(AuthUser $authUser): bool
    {
        return $authUser->can('RestoreAny:MetricUnit');
    }

    public function replicate(AuthUser $authUser, MetricUnit $metricUnit): bool
    {
        return $authUser->can('Replicate:MetricUnit');
    }

    public function reorder(AuthUser $authUser): bool
    {
        return $authUser->can('Reorder:MetricUnit');
    }

}