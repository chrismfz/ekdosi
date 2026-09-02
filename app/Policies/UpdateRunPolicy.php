<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\UpdateRun;
use App\Models\User;
use App\Services\TenantRoleProvisioner;
use Illuminate\Auth\Access\HandlesAuthorization;
use Illuminate\Foundation\Auth\User as AuthUser;

/**
 * `UpdateRun` (ιστορικό/πρόοδος ενημερώσεων εφαρμογής) — GLOBAL, cross-tenant,
 * super-admin-only, IMMUTABLE.
 *
 * Γραμμένη ΣΤΟ ΧΕΡΙ, ΟΧΙ από το `shield:generate` — και για δύο λόγους:
 *
 *  1. **Ασφάλεια.** Το stock Shield template δίνει κάθε CRUD action με βάση τα
 *     `*:UpdateRun` permissions· αυτά τα μοιράζει ο `TenantRoleProvisioner` σε
 *     ΚΑΘΕ `company_admin`, οπότε ένας διαχειριστής εταιρείας θα περνούσε
 *     `Gate::authorize()` πάνω σε ένα cross-tenant deploy record (απορρίφθηκε
 *     στο review του PR #389). Εδώ η μόνη πηγή αλήθειας είναι ο system super
 *     admin — και το `UpdateRun` μπήκε πλέον και στο
 *     `TenantRoleProvisioner::ADMIN_FORBIDDEN_RESOURCES`, ώστε να μην κρατάει
 *     καν τα permissions ο company_admin (άμυνα σε δύο επίπεδα).
 *  2. **Deploy.** Χωρίς ΑΡΧΕΙΟ policy, το `shield:generate` (βήμα 10 του
 *     `deploy/update.sh`, αλλά και ο seeder σε κάθε run της σουίτας) το ΞΑΝΑΓΡΑΦΕ
 *     ως untracked αρχείο· το pre-flight «καθαρό working tree» μπλόκαρε μετά
 *     κάθε επόμενο deploy, χωρίς `git stash` να το καθαρίζει. Με το αρχείο
 *     committed, το `--ignore-existing-policies` το προσπερνά για πάντα.
 *
 * Οι εγγραφές δημιουργούνται ΜΟΝΟ από την ενέργεια «Εγκατάσταση ενημέρωσης» της
 * σελίδας SystemHealth και γράφονται από τον worker `ekdosi:self-update` — ποτέ
 * από φόρμα του panel. Άρα ΚΑΘΕ mutation εδώ είναι `false`.
 *
 * ΣΗΜΕΙΩΣΗ: ο system super admin έχει global `Gate::before` bypass
 * (AppServiceProvider), οπότε γι' αυτόν οι έλεγχοι δεν φτάνουν ποτέ εδώ. Αυτό
 * που κλειδώνει αυτή η policy είναι όλοι οι ΥΠΟΛΟΙΠΟΙ (company_admin / operator
 * / απλός χρήστης) — και για αυτούς είναι default-deny.
 */
class UpdateRunPolicy
{
    use HandlesAuthorization;

    public function viewAny(AuthUser $authUser): bool
    {
        return $this->isSuperAdmin($authUser);
    }

    public function view(AuthUser $authUser, UpdateRun $updateRun): bool
    {
        return $this->isSuperAdmin($authUser);
    }

    // ── Immutable audit trail: no mutation, for anyone the Gate reaches ──────

    public function create(AuthUser $authUser): bool
    {
        return false;
    }

    public function update(AuthUser $authUser, UpdateRun $updateRun): bool
    {
        return false;
    }

    public function delete(AuthUser $authUser, UpdateRun $updateRun): bool
    {
        return false;
    }

    public function deleteAny(AuthUser $authUser): bool
    {
        return false;
    }

    public function restore(AuthUser $authUser, UpdateRun $updateRun): bool
    {
        return false;
    }

    public function restoreAny(AuthUser $authUser): bool
    {
        return false;
    }

    public function forceDelete(AuthUser $authUser, UpdateRun $updateRun): bool
    {
        return false;
    }

    public function forceDeleteAny(AuthUser $authUser): bool
    {
        return false;
    }

    public function replicate(AuthUser $authUser, UpdateRun $updateRun): bool
    {
        return false;
    }

    public function reorder(AuthUser $authUser): bool
    {
        return false;
    }

    /**
     * Ίδιος ορισμός με το `UpdateRunResource::isSuperAdmin()` / `SystemHealth`:
     * super_admin σε ΟΠΟΙΑΔΗΠΟΤΕ εταιρεία (δεν είναι per-tenant δικαίωμα).
     */
    private function isSuperAdmin(AuthUser $authUser): bool
    {
        return $authUser instanceof User
            && app(TenantRoleProvisioner::class)->isSuperAdminAnywhere($authUser);
    }
}
