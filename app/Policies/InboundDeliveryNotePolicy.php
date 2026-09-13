<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\InboundDeliveryNote;
use Illuminate\Auth\Access\HandlesAuthorization;
use Illuminate\Foundation\Auth\User as AuthUser;

class InboundDeliveryNotePolicy
{
    use HandlesAuthorization;

    public function viewAny(AuthUser $authUser): bool
    {
        return $authUser->can('ViewAny:InboundDeliveryNote');
    }

    public function view(AuthUser $authUser, InboundDeliveryNote $inboundDeliveryNote): bool
    {
        return $authUser->can('View:InboundDeliveryNote');
    }

    public function create(AuthUser $authUser): bool
    {
        return $authUser->can('Create:InboundDeliveryNote');
    }

    public function update(AuthUser $authUser, InboundDeliveryNote $inboundDeliveryNote): bool
    {
        return $authUser->can('Update:InboundDeliveryNote');
    }

    public function delete(AuthUser $authUser, InboundDeliveryNote $inboundDeliveryNote): bool
    {
        return $authUser->can('Delete:InboundDeliveryNote');
    }

    public function deleteAny(AuthUser $authUser): bool
    {
        return $authUser->can('DeleteAny:InboundDeliveryNote');
    }

    public function restore(AuthUser $authUser, InboundDeliveryNote $inboundDeliveryNote): bool
    {
        return $authUser->can('Restore:InboundDeliveryNote');
    }

    public function forceDelete(AuthUser $authUser, InboundDeliveryNote $inboundDeliveryNote): bool
    {
        return $authUser->can('ForceDelete:InboundDeliveryNote');
    }
}
