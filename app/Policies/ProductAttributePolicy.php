<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\ProductAttribute;
use Illuminate\Auth\Access\HandlesAuthorization;
use Illuminate\Foundation\Auth\User as AuthUser;

class ProductAttributePolicy
{
    use HandlesAuthorization;

    public function viewAny(AuthUser $authUser): bool
    {
        return $authUser->can('ViewAny:ProductAttribute');
    }

    public function view(AuthUser $authUser, ProductAttribute $productAttribute): bool
    {
        return $authUser->can('View:ProductAttribute');
    }

    public function create(AuthUser $authUser): bool
    {
        return $authUser->can('Create:ProductAttribute');
    }

    public function update(AuthUser $authUser, ProductAttribute $productAttribute): bool
    {
        return $authUser->can('Update:ProductAttribute');
    }

    public function delete(AuthUser $authUser, ProductAttribute $productAttribute): bool
    {
        return $authUser->can('Delete:ProductAttribute');
    }

    public function deleteAny(AuthUser $authUser): bool
    {
        return $authUser->can('DeleteAny:ProductAttribute');
    }

    public function restore(AuthUser $authUser, ProductAttribute $productAttribute): bool
    {
        return $authUser->can('Restore:ProductAttribute');
    }

    public function forceDelete(AuthUser $authUser, ProductAttribute $productAttribute): bool
    {
        return $authUser->can('ForceDelete:ProductAttribute');
    }

    public function forceDeleteAny(AuthUser $authUser): bool
    {
        return $authUser->can('ForceDeleteAny:ProductAttribute');
    }

    public function restoreAny(AuthUser $authUser): bool
    {
        return $authUser->can('RestoreAny:ProductAttribute');
    }

    public function replicate(AuthUser $authUser, ProductAttribute $productAttribute): bool
    {
        return $authUser->can('Replicate:ProductAttribute');
    }

    public function reorder(AuthUser $authUser): bool
    {
        return $authUser->can('Reorder:ProductAttribute');
    }
}
