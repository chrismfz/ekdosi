<?php

declare(strict_types=1);

namespace App\Policies;

use Illuminate\Foundation\Auth\User as AuthUser;
use App\Models\DeliveryNote;
use Illuminate\Auth\Access\HandlesAuthorization;

class DeliveryNotePolicy
{
    use HandlesAuthorization;
    
    public function viewAny(AuthUser $authUser): bool
    {
        return $authUser->can('ViewAny:DeliveryNote');
    }

    public function view(AuthUser $authUser, DeliveryNote $deliveryNote): bool
    {
        return $authUser->can('View:DeliveryNote');
    }

    public function create(AuthUser $authUser): bool
    {
        return $authUser->can('Create:DeliveryNote');
    }

    public function update(AuthUser $authUser, DeliveryNote $deliveryNote): bool
    {
        return $authUser->can('Update:DeliveryNote');
    }

    public function delete(AuthUser $authUser, DeliveryNote $deliveryNote): bool
    {
        return $authUser->can('Delete:DeliveryNote');
    }

    public function deleteAny(AuthUser $authUser): bool
    {
        return $authUser->can('DeleteAny:DeliveryNote');
    }

    public function restore(AuthUser $authUser, DeliveryNote $deliveryNote): bool
    {
        return $authUser->can('Restore:DeliveryNote');
    }

    public function forceDelete(AuthUser $authUser, DeliveryNote $deliveryNote): bool
    {
        return $authUser->can('ForceDelete:DeliveryNote');
    }

    public function forceDeleteAny(AuthUser $authUser): bool
    {
        return $authUser->can('ForceDeleteAny:DeliveryNote');
    }

    public function restoreAny(AuthUser $authUser): bool
    {
        return $authUser->can('RestoreAny:DeliveryNote');
    }

    public function replicate(AuthUser $authUser, DeliveryNote $deliveryNote): bool
    {
        return $authUser->can('Replicate:DeliveryNote');
    }

    public function reorder(AuthUser $authUser): bool
    {
        return $authUser->can('Reorder:DeliveryNote');
    }

}