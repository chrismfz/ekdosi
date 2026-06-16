<?php

declare(strict_types=1);

namespace App\Policies;

use Illuminate\Foundation\Auth\User as AuthUser;
use App\Models\ExpenseClassificationRule;
use Illuminate\Auth\Access\HandlesAuthorization;

class ExpenseClassificationRulePolicy
{
    use HandlesAuthorization;
    
    public function viewAny(AuthUser $authUser): bool
    {
        return $authUser->can('ViewAny:ExpenseClassificationRule');
    }

    public function view(AuthUser $authUser, ExpenseClassificationRule $expenseClassificationRule): bool
    {
        return $authUser->can('View:ExpenseClassificationRule');
    }

    public function create(AuthUser $authUser): bool
    {
        return $authUser->can('Create:ExpenseClassificationRule');
    }

    public function update(AuthUser $authUser, ExpenseClassificationRule $expenseClassificationRule): bool
    {
        return $authUser->can('Update:ExpenseClassificationRule');
    }

    public function delete(AuthUser $authUser, ExpenseClassificationRule $expenseClassificationRule): bool
    {
        return $authUser->can('Delete:ExpenseClassificationRule');
    }

    public function deleteAny(AuthUser $authUser): bool
    {
        return $authUser->can('DeleteAny:ExpenseClassificationRule');
    }

    public function restore(AuthUser $authUser, ExpenseClassificationRule $expenseClassificationRule): bool
    {
        return $authUser->can('Restore:ExpenseClassificationRule');
    }

    public function forceDelete(AuthUser $authUser, ExpenseClassificationRule $expenseClassificationRule): bool
    {
        return $authUser->can('ForceDelete:ExpenseClassificationRule');
    }

    public function forceDeleteAny(AuthUser $authUser): bool
    {
        return $authUser->can('ForceDeleteAny:ExpenseClassificationRule');
    }

    public function restoreAny(AuthUser $authUser): bool
    {
        return $authUser->can('RestoreAny:ExpenseClassificationRule');
    }

    public function replicate(AuthUser $authUser, ExpenseClassificationRule $expenseClassificationRule): bool
    {
        return $authUser->can('Replicate:ExpenseClassificationRule');
    }

    public function reorder(AuthUser $authUser): bool
    {
        return $authUser->can('Reorder:ExpenseClassificationRule');
    }

}