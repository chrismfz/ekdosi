<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\WorkCardEvent;
use Illuminate\Auth\Access\HandlesAuthorization;
use Illuminate\Foundation\Auth\User as AuthUser;

/** Shield-style policy (same shape shield:generate emits) — company_admin only by default. */
class WorkCardEventPolicy
{
    use HandlesAuthorization;

    public function viewAny(AuthUser $authUser): bool
    {
        return $authUser->can('ViewAny:WorkCardEvent');
    }

    public function view(AuthUser $authUser, WorkCardEvent $workCardEvent): bool
    {
        return $authUser->can('View:WorkCardEvent');
    }

    public function create(AuthUser $authUser): bool
    {
        return $authUser->can('Create:WorkCardEvent');
    }

    public function update(AuthUser $authUser, WorkCardEvent $workCardEvent): bool
    {
        return $authUser->can('Update:WorkCardEvent');
    }

    public function delete(AuthUser $authUser, WorkCardEvent $workCardEvent): bool
    {
        return $authUser->can('Delete:WorkCardEvent');
    }

    public function deleteAny(AuthUser $authUser): bool
    {
        return $authUser->can('DeleteAny:WorkCardEvent');
    }

    public function restore(AuthUser $authUser, WorkCardEvent $workCardEvent): bool
    {
        return $authUser->can('Restore:WorkCardEvent');
    }

    public function forceDelete(AuthUser $authUser, WorkCardEvent $workCardEvent): bool
    {
        return $authUser->can('ForceDelete:WorkCardEvent');
    }

    public function forceDeleteAny(AuthUser $authUser): bool
    {
        return $authUser->can('ForceDeleteAny:WorkCardEvent');
    }

    public function restoreAny(AuthUser $authUser): bool
    {
        return $authUser->can('RestoreAny:WorkCardEvent');
    }
}
