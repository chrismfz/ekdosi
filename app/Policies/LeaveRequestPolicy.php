<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\LeaveRequest;
use App\Models\User;
use Illuminate\Auth\Access\HandlesAuthorization;
use Illuminate\Foundation\Auth\User as AuthUser;

/**
 * Leave requests. Two tiers:
 *   - staff (operator / ergani): ViewAny+View+Create — their OWN requests only
 *     (the resource query is narrowed too); may cancel their own PENDING one.
 *   - approver (Update:LeaveRequest — company_admin): everyone's, decides.
 * No delete: a leave is cancelled, never erased (it's what the accountant was told).
 */
class LeaveRequestPolicy
{
    use HandlesAuthorization;

    public static function isApprover(?AuthUser $user): bool
    {
        return $user !== null && $user->can('Update:LeaveRequest');
    }

    public static function owns(?AuthUser $user, LeaveRequest $leave): bool
    {
        return $user instanceof User
            && (int) $leave->employee?->user_id === (int) $user->getKey();
    }

    public function viewAny(AuthUser $authUser): bool
    {
        return $authUser->can('ViewAny:LeaveRequest');
    }

    public function view(AuthUser $authUser, LeaveRequest $leaveRequest): bool
    {
        return $authUser->can('View:LeaveRequest')
            && (self::isApprover($authUser) || self::owns($authUser, $leaveRequest));
    }

    public function create(AuthUser $authUser): bool
    {
        return $authUser->can('Create:LeaveRequest');
    }

    /** Approve / reject / revoke — approvers only. */
    public function update(AuthUser $authUser, LeaveRequest $leaveRequest): bool
    {
        return self::isApprover($authUser);
    }

    /** Withdraw: the owner while pending; an approver any time before it's closed. */
    public function cancel(AuthUser $authUser, LeaveRequest $leaveRequest): bool
    {
        if (self::isApprover($authUser)) {
            return $leaveRequest->isPending() || $leaveRequest->isApproved();
        }

        return $leaveRequest->isPending() && self::owns($authUser, $leaveRequest);
    }

    public function delete(AuthUser $authUser, LeaveRequest $leaveRequest): bool
    {
        return false;
    }

    public function deleteAny(AuthUser $authUser): bool
    {
        return false;
    }
}
