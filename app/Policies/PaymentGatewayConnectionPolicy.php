<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\PaymentGatewayConnection;
use Illuminate\Auth\Access\HandlesAuthorization;
use Illuminate\Foundation\Auth\User as AuthUser;

class PaymentGatewayConnectionPolicy
{
    use HandlesAuthorization;

    public function viewAny(AuthUser $authUser): bool
    {
        return $authUser->can('ViewAny:PaymentGatewayConnection');
    }

    public function view(AuthUser $authUser, PaymentGatewayConnection $paymentGatewayConnection): bool
    {
        return $authUser->can('View:PaymentGatewayConnection');
    }

    public function create(AuthUser $authUser): bool
    {
        return $authUser->can('Create:PaymentGatewayConnection');
    }

    public function update(AuthUser $authUser, PaymentGatewayConnection $paymentGatewayConnection): bool
    {
        return $authUser->can('Update:PaymentGatewayConnection');
    }

    public function delete(AuthUser $authUser, PaymentGatewayConnection $paymentGatewayConnection): bool
    {
        return $authUser->can('Delete:PaymentGatewayConnection');
    }

    public function deleteAny(AuthUser $authUser): bool
    {
        return $authUser->can('DeleteAny:PaymentGatewayConnection');
    }

    public function restore(AuthUser $authUser, PaymentGatewayConnection $paymentGatewayConnection): bool
    {
        return $authUser->can('Restore:PaymentGatewayConnection');
    }

    public function forceDelete(AuthUser $authUser, PaymentGatewayConnection $paymentGatewayConnection): bool
    {
        return $authUser->can('ForceDelete:PaymentGatewayConnection');
    }

    public function forceDeleteAny(AuthUser $authUser): bool
    {
        return $authUser->can('ForceDeleteAny:PaymentGatewayConnection');
    }

    public function restoreAny(AuthUser $authUser): bool
    {
        return $authUser->can('RestoreAny:PaymentGatewayConnection');
    }

    public function replicate(AuthUser $authUser, PaymentGatewayConnection $paymentGatewayConnection): bool
    {
        return $authUser->can('Replicate:PaymentGatewayConnection');
    }

    public function reorder(AuthUser $authUser): bool
    {
        return $authUser->can('Reorder:PaymentGatewayConnection');
    }
}
