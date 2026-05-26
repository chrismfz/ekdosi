<?php

declare(strict_types=1);

namespace App\Policies;

use Illuminate\Foundation\Auth\User as AuthUser;
use App\Models\DeliveryMethod;
use Illuminate\Auth\Access\HandlesAuthorization;

class DeliveryMethodPolicy
{
    use HandlesAuthorization;
    
    public function viewAny(AuthUser $authUser): bool
    {
        return $authUser->can('ViewAny:DeliveryMethod');
    }

    public function view(AuthUser $authUser, DeliveryMethod $deliveryMethod): bool
    {
        return $authUser->can('View:DeliveryMethod');
    }

    public function create(AuthUser $authUser): bool
    {
        return $authUser->can('Create:DeliveryMethod');
    }

    public function update(AuthUser $authUser, DeliveryMethod $deliveryMethod): bool
    {
        return $authUser->can('Update:DeliveryMethod');
    }

    public function delete(AuthUser $authUser, DeliveryMethod $deliveryMethod): bool
    {
        return $authUser->can('Delete:DeliveryMethod');
    }

    public function deleteAny(AuthUser $authUser): bool
    {
        return $authUser->can('DeleteAny:DeliveryMethod');
    }

    public function restore(AuthUser $authUser, DeliveryMethod $deliveryMethod): bool
    {
        return $authUser->can('Restore:DeliveryMethod');
    }

    public function forceDelete(AuthUser $authUser, DeliveryMethod $deliveryMethod): bool
    {
        return $authUser->can('ForceDelete:DeliveryMethod');
    }

    public function forceDeleteAny(AuthUser $authUser): bool
    {
        return $authUser->can('ForceDeleteAny:DeliveryMethod');
    }

    public function restoreAny(AuthUser $authUser): bool
    {
        return $authUser->can('RestoreAny:DeliveryMethod');
    }

    public function replicate(AuthUser $authUser, DeliveryMethod $deliveryMethod): bool
    {
        return $authUser->can('Replicate:DeliveryMethod');
    }

    public function reorder(AuthUser $authUser): bool
    {
        return $authUser->can('Reorder:DeliveryMethod');
    }

}