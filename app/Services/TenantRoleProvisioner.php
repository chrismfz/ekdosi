<?php

namespace App\Services;

use App\Models\Company;
use App\Models\Role;
use App\Models\User;
use BezhanSalleh\FilamentShield\Support\Utils as ShieldUtils;
use Illuminate\Support\Collection;
use Spatie\Permission\Models\Permission;
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

    // ── Standard non-super roles ───────────────────────────────────────────
    //
    // Under Shield teams mode the ROLE rows are per-company (team-scoped) but
    // the PERMISSION rows are global (Spatie default — only model_has_roles /
    // role_has_permissions carry the team id). So we create a company_admin and
    // an operator role per tenant and attach the right slice of the GLOBAL
    // permissions to each. Unlike super_admin, these roles do NOT trigger the
    // Gate::before bypass — they're enforced by the actual permission set, and
    // are confined to their own tenant by the team scope on assignment.

    public const ROLE_COMPANY_ADMIN = 'company_admin';

    public const ROLE_OPERATOR = 'operator';

    /**
     * Resource permission prefixes an OPERATOR gets: issue + manage the daily
     * documents/people/money, but NOT delete, NOT Setup lookups, NOT users,
     * NOT company/myDATA credentials. Keyed by Shield resource permission base
     * name (the part after the prefix, e.g. "Invoice" in "ViewAny:Invoice").
     *
     * @var list<string>
     */
    public const OPERATOR_RESOURCES = [
        'Invoice', 'Quote', 'Customer', 'Product', 'Payment', 'Expense', 'Supplier',
        // The WHMCS inbox is daily operator work (review staged invoices, file
        // them). Create:* isn't generated for it (no create policy method) — the
        // operatorPermissions() whereIn filter drops the non-existent combos.
        'PendingWhmcsInvoice',
    ];

    /**
     * The action prefixes an operator may perform on the above resources.
     * No delete/forceDelete/restore — destructive ops stay with the admin.
     *
     * @var list<string>
     */
    public const OPERATOR_ACTIONS = ['ViewAny', 'View', 'Create', 'Update'];

    /**
     * Ensure a tenant has the standard non-super roles (company_admin,
     * operator) with their permission sets attached. Idempotent + team-scoped.
     * Call from the CompanyObserver (alongside ensureSuperAdminRole) and the
     * backfill command. No-op-safe to re-run after shield:generate adds new
     * permissions — it re-syncs the maps.
     */
    public function ensureStandardRoles(Company $company): void
    {
        $registrar = app(PermissionRegistrar::class);
        $previousTeam = $registrar->getPermissionsTeamId();
        $registrar->setPermissionsTeamId($company->getKey());

        try {
            $guard = ShieldUtils::getFilamentAuthGuard();

            // company_admin = every permission that exists (full control of THIS
            // tenant), but NOT the super_admin role → no cross-tenant bypass.
            $admin = Role::query()->firstOrCreate([
                'name' => self::ROLE_COMPANY_ADMIN,
                'guard_name' => $guard,
                'company_id' => $company->getKey(),
            ]);
            $admin->syncPermissions(Permission::query()->where('guard_name', $guard)->get());

            // operator = curated subset (see OPERATOR_* maps).
            $operator = Role::query()->firstOrCreate([
                'name' => self::ROLE_OPERATOR,
                'guard_name' => $guard,
                'company_id' => $company->getKey(),
            ]);
            $operator->syncPermissions($this->operatorPermissions($guard));
        } finally {
            $registrar->setPermissionsTeamId($previousTeam);
            $registrar->forgetCachedPermissions();
        }
    }

    /**
     * The global Permission rows an operator role should hold:
     * {action}:{resource} for the curated resource/action maps. Filters to
     * permissions that actually exist (shield:generate may not have created
     * every combination — e.g. a resource without a Create policy method).
     *
     * @return Collection<int, Permission>
     */
    private function operatorPermissions(string $guard): Collection
    {
        $wanted = [];
        foreach (self::OPERATOR_RESOURCES as $resource) {
            foreach (self::OPERATOR_ACTIONS as $action) {
                $wanted[] = "{$action}:{$resource}";
            }
        }

        return Permission::query()
            ->where('guard_name', $guard)
            ->whereIn('name', $wanted)
            ->get();
    }

    /**
     * Assign a standard role to a user within a company's team. Mirrors
     * assignSuperAdmin's cache discipline. Pass self::ROLE_COMPANY_ADMIN or
     * ROLE_OPERATOR. Ensures the roles exist first.
     */
    public function assignStandardRole(User $user, Company $company, string $roleName): void
    {
        if (! in_array($roleName, [self::ROLE_COMPANY_ADMIN, self::ROLE_OPERATOR], true)) {
            throw new \InvalidArgumentException("Unknown standard role: {$roleName}");
        }

        $this->ensureStandardRoles($company);

        $registrar = app(PermissionRegistrar::class);
        $previousTeam = $registrar->getPermissionsTeamId();
        $registrar->setPermissionsTeamId($company->getKey());

        try {
            $registrar->forgetCachedPermissions();
            $user->unsetRelation('roles');
            if (! $user->hasRole($roleName)) {
                $user->assignRole($roleName);
            }
        } finally {
            $registrar->setPermissionsTeamId($previousTeam);
            $registrar->forgetCachedPermissions();
        }
    }

    // ── Role picker (per user × company) ───────────────────────────────────
    //
    // The UserResource role-picker treats each (user, company) pivot as holding
    // AT MOST ONE managed role: super_admin | company_admin | operator. These
    // helpers read and set that single role within a team, so the picker is a
    // simple Select rather than a multi-role checkbox list.

    /**
     * The three roles the picker manages, in privilege order. super_admin is
     * resolved dynamically from Shield config (its name is configurable).
     *
     * @return list<string>
     */
    public function managedRoleNames(): array
    {
        return [
            ShieldUtils::getSuperAdminName(),
            self::ROLE_COMPANY_ADMIN,
            self::ROLE_OPERATOR,
        ];
    }

    /**
     * Does the user hold the super_admin role specifically within THIS
     * company's team? (Distinct from isSuperAdminAnywhere — used to decide
     * whether the acting user may grant super_admin in a given tenant, so a
     * company_admin can't escalate.)
     */
    public function hasSuperAdminIn(User $user, Company $company): bool
    {
        $registrar = app(PermissionRegistrar::class);
        $previousTeam = $registrar->getPermissionsTeamId();
        $registrar->setPermissionsTeamId($company->getKey());

        try {
            $registrar->forgetCachedPermissions();
            $user->unsetRelation('roles');

            return $user->hasRole(ShieldUtils::getSuperAdminName());
        } finally {
            $registrar->setPermissionsTeamId($previousTeam);
            $registrar->forgetCachedPermissions();
        }
    }

    /**
     * Which managed role (if any) the user holds in the company's team. Returns
     * the role name (super_admin / company_admin / operator) or null. If more
     * than one is somehow present, returns the highest-privilege one.
     */
    public function roleInCompany(User $user, Company $company): ?string
    {
        $registrar = app(PermissionRegistrar::class);
        $previousTeam = $registrar->getPermissionsTeamId();
        $registrar->setPermissionsTeamId($company->getKey());

        try {
            $registrar->forgetCachedPermissions();
            $user->unsetRelation('roles');

            foreach ($this->managedRoleNames() as $name) {
                if ($user->hasRole($name)) {
                    return $name;
                }
            }

            return null;
        } finally {
            $registrar->setPermissionsTeamId($previousTeam);
            $registrar->forgetCachedPermissions();
        }
    }

    /**
     * Set the user's single managed role within a company's team (picker
     * semantics): strips any other managed role they hold there first, then
     * assigns the chosen one. Pass null to clear all managed roles (no access
     * beyond plain attach). Ensures the role rows exist first.
     *
     * Does NOT enforce escalation rules — the caller (UI action) decides
     * whether the acting user may grant super_admin. Non-managed roles are
     * left untouched.
     */
    public function setRoleInCompany(User $user, Company $company, ?string $roleName): void
    {
        $managed = $this->managedRoleNames();

        if ($roleName !== null && ! in_array($roleName, $managed, true)) {
            throw new \InvalidArgumentException("Unknown managed role: {$roleName}");
        }

        // Make sure both super_admin and the standard roles exist for this team.
        $this->ensureSuperAdminRole($company);
        $this->ensureStandardRoles($company);

        $registrar = app(PermissionRegistrar::class);
        $previousTeam = $registrar->getPermissionsTeamId();
        $registrar->setPermissionsTeamId($company->getKey());

        try {
            $registrar->forgetCachedPermissions();
            $user->unsetRelation('roles');

            foreach ($managed as $name) {
                if ($name !== $roleName && $user->hasRole($name)) {
                    $user->removeRole($name);
                }
            }

            if ($roleName !== null && ! $user->hasRole($roleName)) {
                $user->assignRole($roleName);
            }
        } finally {
            $registrar->setPermissionsTeamId($previousTeam);
            $registrar->forgetCachedPermissions();
        }
    }
}
