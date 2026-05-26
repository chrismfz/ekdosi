<?php

declare(strict_types=1);

namespace App\Policies;

use Illuminate\Foundation\Auth\User as AuthUser;
use App\Models\VatCategory;
use Illuminate\Auth\Access\HandlesAuthorization;

class VatCategoryPolicy
{
    use HandlesAuthorization;
    
    public function viewAny(AuthUser $authUser): bool
    {
        return $authUser->can('ViewAny:VatCategory');
    }

    public function view(AuthUser $authUser, VatCategory $vatCategory): bool
    {
        return $authUser->can('View:VatCategory');
    }

    public function create(AuthUser $authUser): bool
    {
        return $authUser->can('Create:VatCategory');
    }

    public function update(AuthUser $authUser, VatCategory $vatCategory): bool
    {
        return $authUser->can('Update:VatCategory');
    }

    public function delete(AuthUser $authUser, VatCategory $vatCategory): bool
    {
        return $authUser->can('Delete:VatCategory');
    }

    public function deleteAny(AuthUser $authUser): bool
    {
        return $authUser->can('DeleteAny:VatCategory');
    }

    public function restore(AuthUser $authUser, VatCategory $vatCategory): bool
    {
        return $authUser->can('Restore:VatCategory');
    }

    public function forceDelete(AuthUser $authUser, VatCategory $vatCategory): bool
    {
        return $authUser->can('ForceDelete:VatCategory');
    }

    public function forceDeleteAny(AuthUser $authUser): bool
    {
        return $authUser->can('ForceDeleteAny:VatCategory');
    }

    public function restoreAny(AuthUser $authUser): bool
    {
        return $authUser->can('RestoreAny:VatCategory');
    }

    public function replicate(AuthUser $authUser, VatCategory $vatCategory): bool
    {
        return $authUser->can('Replicate:VatCategory');
    }

    public function reorder(AuthUser $authUser): bool
    {
        return $authUser->can('Reorder:VatCategory');
    }

}