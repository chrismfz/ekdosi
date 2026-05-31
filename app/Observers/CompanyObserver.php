<?php

namespace App\Observers;

use App\Models\Company;
use App\Services\TenantRoleProvisioner;

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
 */
class CompanyObserver
{
    public function __construct(private readonly TenantRoleProvisioner $provisioner) {}

    public function created(Company $company): void
    {
        $this->provisioner->ensureSuperAdminRole($company);
        // company_admin + operator (with their permission sets). Safe even if
        // shield:generate hasn't populated permissions yet — the maps just
        // resolve to whatever exists, and re-running re-syncs.
        $this->provisioner->ensureStandardRoles($company);
    }
}
