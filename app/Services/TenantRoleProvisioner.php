<?php

namespace App\Services;

use App\Models\Company;
use App\Models\Role;
use App\Models\User;
use BezhanSalleh\FilamentShield\Support\Utils as ShieldUtils;
use Spatie\Permission\PermissionRegistrar;

/**
 * Keeps the per-tenant `super_admin` role in sync under Shield's teams mode.
 *
 * Background: Shield runs in teams mode (`team_foreign_key=company_id`), so a
 * `super_admin` role row exists PER company. The super-admin bypass in
 * AppServiceProvider is `Gate::before(fn => $user->hasRole(super_admin))`,
 * evaluated against the CURRENT tenant's team — so the role must exist in,
 * and be assigned within, each company's team.
 *
 * The DatabaseSeeder set this up for the seeded tenants, but a company created
 * later (e.g. from the Companies UI) never got a `super_admin` role for its
 * team, so an admin switching into it lost the bypass and saw a stripped-down
 * menu. This service is the single place that repairs/maintains that, called
 * from the Company observer (role existence) and on user attach (assignment),
 * plus a backfill command.
 *
 * Note: the role needs no attached permissions — the Gate::before bypass
 * short-circuits every check the moment the user has the role. Permissions are
 * only relevant for non-super roles.
 */
class TenantRoleProvisioner
{
    /**
     * Ensure the given company's team has a `super_admin` role row.
     * Idempotent. Returns the role.
     */
    public function ensureSuperAdminRole(Company $company): Role
    {
        $registrar = app(PermissionRegistrar::class);
        $previousTeam = $registrar->getPermissionsTeamId();

        // Role lookups are team-scoped; pin the team for this operation then
        // restore whatever was set (we may be mid-request inside a tenant).
        $registrar->setPermissionsTeamId($company->getKey());

        try {
            /** @var Role $role */
            $role = Role::query()->firstOrCreate(
                [
                    'name' => ShieldUtils::getSuperAdminName(),
                    'guard_name' => ShieldUtils::getFilamentAuthGuard(),
                    'company_id' => $company->getKey(),
                ],
            );

            return $role;
        } finally {
            $registrar->setPermissionsTeamId($previousTeam);
            $registrar->forgetCachedPermissions();
        }
    }

    /**
     * Assign the company's super_admin role to a user (creating the role if
     * needed). Idempotent. Used when a user is attached to a company AND that
     * user is already a super_admin elsewhere — we do NOT blanket-grant
     * super_admin to every attached user (that would defeat per-tenant
     * operators); the caller decides eligibility.
     */
    public function assignSuperAdmin(User $user, Company $company): void
    {
        $role = $this->ensureSuperAdminRole($company);

        $registrar = app(PermissionRegistrar::class);
        $previousTeam = $registrar->getPermissionsTeamId();
        $registrar->setPermissionsTeamId($company->getKey());

        try {
            $registrar->forgetCachedPermissions();
            // Drop any roles relation cached under a different team, else the
            // hasRole() guard reads stale data across team switches.
            $user->unsetRelation('roles');
            if (! $user->hasRole($role)) {
                $user->assignRole($role);
            }
        } finally {
            $registrar->setPermissionsTeamId($previousTeam);
            $registrar->forgetCachedPermissions();
        }
    }

    /**
     * Is the user a super_admin in ANY of their teams? Used to decide whether
     * a newly-attached company should inherit the super_admin grant.
     */
    public function isSuperAdminAnywhere(User $user): bool
    {
        $registrar = app(PermissionRegistrar::class);
        $previousTeam = $registrar->getPermissionsTeamId();
        $superName = ShieldUtils::getSuperAdminName();

        try {
            foreach ($user->companies as $company) {
                $registrar->setPermissionsTeamId($company->getKey());
                $registrar->forgetCachedPermissions();
                // Force a fresh team-scoped roles load each iteration —
                // otherwise the relation cached for the first team masks a
                // super_admin grant that lives in a later team.
                $user->unsetRelation('roles');
                if ($user->hasRole($superName)) {
                    return true;
                }
            }

            return false;
        } finally {
            $registrar->setPermissionsTeamId($previousTeam);
            $registrar->forgetCachedPermissions();
        }
    }
}
