<?php

declare(strict_types=1);

namespace App\Policies;

use Illuminate\Foundation\Auth\User as AuthUser;
use App\Models\PaymentGatewayEvent;
use Illuminate\Auth\Access\HandlesAuthorization;

class PaymentGatewayEventPolicy
{
    use HandlesAuthorization;
    
    public function viewAny(AuthUser $authUser): bool
    {
        return $authUser->can('ViewAny:PaymentGatewayEvent');
    }

    public function view(AuthUser $authUser, PaymentGatewayEvent $paymentGatewayEvent): bool
    {
        return $authUser->can('View:PaymentGatewayEvent');
    }

    public function create(AuthUser $authUser): bool
    {
        return $authUser->can('Create:PaymentGatewayEvent');
    }

    public function update(AuthUser $authUser, PaymentGatewayEvent $paymentGatewayEvent): bool
    {
        return $authUser->can('Update:PaymentGatewayEvent');
    }

    public function delete(AuthUser $authUser, PaymentGatewayEvent $paymentGatewayEvent): bool
    {
        return $authUser->can('Delete:PaymentGatewayEvent');
    }

    public function deleteAny(AuthUser $authUser): bool
    {
        return $authUser->can('DeleteAny:PaymentGatewayEvent');
    }

    public function restore(AuthUser $authUser, PaymentGatewayEvent $paymentGatewayEvent): bool
    {
        return $authUser->can('Restore:PaymentGatewayEvent');
    }

    public function forceDelete(AuthUser $authUser, PaymentGatewayEvent $paymentGatewayEvent): bool
    {
        return $authUser->can('ForceDelete:PaymentGatewayEvent');
    }

    public function forceDeleteAny(AuthUser $authUser): bool
    {
        return $authUser->can('ForceDeleteAny:PaymentGatewayEvent');
    }

    public function restoreAny(AuthUser $authUser): bool
    {
        return $authUser->can('RestoreAny:PaymentGatewayEvent');
    }

    public function replicate(AuthUser $authUser, PaymentGatewayEvent $paymentGatewayEvent): bool
    {
        return $authUser->can('Replicate:PaymentGatewayEvent');
    }

    public function reorder(AuthUser $authUser): bool
    {
        return $authUser->can('Reorder:PaymentGatewayEvent');
    }

}