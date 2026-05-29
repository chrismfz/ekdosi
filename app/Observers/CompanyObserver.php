<?php

namespace App\Observers;

use App\Models\Company;
use App\Services\TenantRoleProvisioner;

/**
 * Ensures every company — however it's created (Companies UI, factory, future
 * code paths) — gets its per-team `super_admin` role straight away. Mirrors
 * what the DatabaseSeeder did for the seeded tenants, so a UI-created tenant
 * is no longer missing the role that the super-admin bypass depends on.
 *
 * Assignment to a specific user happens on attach (see the relation managers)
 * or via `shield:sync-super-admin`; the observer only guarantees the role
 * EXISTS for the team.
 */
class CompanyObserver
{
    public function __construct(private readonly TenantRoleProvisioner $provisioner) {}

    public function created(Company $company): void
    {
        $this->provisioner->ensureSuperAdminRole($company);
    }
}
