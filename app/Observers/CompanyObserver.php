<?php

namespace App\Observers;

use App\Models\Company;
use App\Services\TenantRoleProvisioner;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\PermissionRegistrar;

/**
 * Ensures every company — however it's created (Companies UI, factory, future
 * code paths) — gets its per-team roles straight away: the `super_admin` role
 * (whose existence the Gate::before bypass depends on) AND the standard
 * non-super roles (company_admin, operator). Mirrors what the DatabaseSeeder
 * did for the seeded tenants, so a UI-created tenant isn't missing roles.
 *
 * Assignment to a specific user happens on attach (see the relation managers)
 * or via `shield:sync-super-admin`; the observer only guarantees the roles
 * EXIST for the team.
 *
 * On delete it cleans the tenant's spatie roles: `roles.company_id` (teams
 * mode) has NO FK cascade to `companies`, so a deleted tenant would otherwise
 * leave ORPHAN roles — which then collide ("Duplicate entry … super_admin")
 * when a later company reuses the freed auto-increment id (e.g. after a MariaDB
 * restart). Pivots (model_has_roles / role_has_permissions) cascade from roles.
 */
class CompanyObserver
{
    public function __construct(private readonly TenantRoleProvisioner $provisioner) {}

    public function created(Company $company): void
    {
        $this->provisioner->ensureSuperAdminRole($company);
        // company_admin + operator with their permission maps. This attaches
        // whatever permissions EXIST right now: on a normal deploy shield:generate
        // already ran at install, so a UI-created tenant gets the full maps. On a
        // brand-new install where permissions don't exist yet, the role rows are
        // created (possibly empty) and must be re-synced by running
        // `php artisan shield:sync-super-admin` once after shield:generate — there
        // is no automatic re-sync for tenants created before permissions exist.
        $this->provisioner->ensureStandardRoles($company);
    }

    /**
     * After a tenant is deleted, drop its roles so a reused company id can't
     * collide with leftovers. `deleted` (not `deleting`) so it only runs once the
     * delete actually succeeded. Pivots (model_has_roles / role_has_permissions)
     * cascade from roles. Note: Filament's delete actions don't wrap the delete in
     * a transaction, so this is NOT atomic with the company delete — on the happy
     * path the roles are dropped right after; a one-off leftover is mopped up by
     * `ekdosi:prune-orphan-roles`.
     */
    public function deleted(Company $company): void
    {
        DB::table('roles')->where('company_id', $company->getKey())->delete();

        // The role rows are gone; bust spatie's permission cache so it doesn't
        // serve a stale role→permission map referencing them.
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
}
