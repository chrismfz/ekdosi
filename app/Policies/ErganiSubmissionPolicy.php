<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\ErganiSubmission;
use Illuminate\Auth\Access\HandlesAuthorization;
use Illuminate\Foundation\Auth\User as AuthUser;

/**
 * «Ιστορικό ΕΡΓΑΝΗ» is an APPEND-ONLY legal audit (every call to ΕΡΓΑΝΗ, full
 * request/response): read-only for everyone — no create/update/delete ability
 * exists at all, whatever the role.
 */
class ErganiSubmissionPolicy
{
    use HandlesAuthorization;

    public function viewAny(AuthUser $authUser): bool
    {
        return $authUser->can('ViewAny:ErganiSubmission');
    }

    public function view(AuthUser $authUser, ErganiSubmission $erganiSubmission): bool
    {
        return $authUser->can('View:ErganiSubmission');
    }

    public function create(AuthUser $authUser): bool
    {
        return false;
    }

    public function update(AuthUser $authUser, ErganiSubmission $erganiSubmission): bool
    {
        return false;
    }

    public function delete(AuthUser $authUser, ErganiSubmission $erganiSubmission): bool
    {
        return false;
    }

    public function deleteAny(AuthUser $authUser): bool
    {
        return false;
    }
}
