<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\CompanyHoliday;
use Illuminate\Auth\Access\HandlesAuthorization;
use Illuminate\Foundation\Auth\User as AuthUser;

/** Shield-style policy (same shape shield:generate emits) — company_admin only by default. */
class CompanyHolidayPolicy
{
    use HandlesAuthorization;

    public function viewAny(AuthUser $authUser): bool
    {
        return $authUser->can('ViewAny:CompanyHoliday');
    }

    public function view(AuthUser $authUser, CompanyHoliday $companyHoliday): bool
    {
        return $authUser->can('View:CompanyHoliday');
    }

    public function create(AuthUser $authUser): bool
    {
        return $authUser->can('Create:CompanyHoliday');
    }

    public function update(AuthUser $authUser, CompanyHoliday $companyHoliday): bool
    {
        return $authUser->can('Update:CompanyHoliday');
    }

    public function delete(AuthUser $authUser, CompanyHoliday $companyHoliday): bool
    {
        return $authUser->can('Delete:CompanyHoliday');
    }

    public function deleteAny(AuthUser $authUser): bool
    {
        return $authUser->can('DeleteAny:CompanyHoliday');
    }

    public function restore(AuthUser $authUser, CompanyHoliday $companyHoliday): bool
    {
        return $authUser->can('Restore:CompanyHoliday');
    }

    public function forceDelete(AuthUser $authUser, CompanyHoliday $companyHoliday): bool
    {
        return $authUser->can('ForceDelete:CompanyHoliday');
    }

    public function forceDeleteAny(AuthUser $authUser): bool
    {
        return $authUser->can('ForceDeleteAny:CompanyHoliday');
    }

    public function restoreAny(AuthUser $authUser): bool
    {
        return $authUser->can('RestoreAny:CompanyHoliday');
    }
}
