<?php

namespace App\Services;

use App\Models\Company;
use App\Models\Role;
use App\Models\User;
use BezhanSalleh\FilamentShield\Support\Utils as ShieldUtils;
use Closure;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

/**
 * Provisions + maintains the per-tenant managed roles under Shield's teams mode.
 *
 * Background: Shield runs in teams mode (`team_foreign_key=company_id`), so a
 * role row exists PER company. The super-admin bypass in AppServiceProvider is
 * `Gate::before(fn => $user->hasRole(super_admin))`, evaluated against the
 * CURRENT tenant's team — so a role must exist in, and be assigned within, each
 * company's team. This service is the single place that creates/repairs those
 * roles and assigns them, called from the CompanyObserver, the DatabaseSeeder,
 * the `shield:sync-super-admin` backfill command, and the role-picker UI.
 *
 * Three managed roles per tenant:
 *   - super_admin   : Gate::before bypass; needs no permissions.
 *   - company_admin : every permission of THIS tenant EXCEPT the cross-tenant /
 *                     platform-level resources in ADMIN_FORBIDDEN_RESOURCES
 *                     (User/Company/Role) — so a tenant admin can't reach the
 *                     panel-global user/company roster or escalate roles.
 *   - operator      : the curated daily-work subset (OPERATOR_PERMISSION_MAP).
 *
 * Team-cache discipline: every role read/write must pin the registrar's team id
 * and restore it afterwards, and a WRITE must bust the permission cache. All of
 * that lives in one place — `withTeam()` — so no caller hand-rolls (and forgets
 * a step of) the envelope.
 */
class TenantRoleProvisioner
{
    public const ROLE_COMPANY_ADMIN = 'company_admin';

    public const ROLE_OPERATOR = 'operator';

    /**
     * The OPERATOR role's permissions, as an explicit per-resource action map
     * (resources have heterogeneous action sets — the WHMCS inbox has no Create,
     * the MARK-detail page is a single read-only View). {action}:{resource}
     * combos that shield:generate didn't create are dropped by operatorPermissions().
     *
     * @var array<string, list<string>>
     */
    public const OPERATOR_PERMISSION_MAP = [
        'Invoice' => ['ViewAny', 'View', 'Create', 'Update'],
        'Quote' => ['ViewAny', 'View', 'Create', 'Update'],
        'Customer' => ['ViewAny', 'View', 'Create', 'Update'],
        'Product' => ['ViewAny', 'View', 'Create', 'Update'],
        'Payment' => ['ViewAny', 'View', 'Create', 'Update'],
        'Expense' => ['ViewAny', 'View', 'Create', 'Update'],
        'Supplier' => ['ViewAny', 'View', 'Create', 'Update'],
        // WHMCS inbox: daily operator work (review/file staged invoices). No
        // Create:* exists (rows arrive via ingestion only).
        'PendingWhmcsInvoice' => ['ViewAny', 'View', 'Update'],
        // Read-only ΜΑΡΚ drill-down, linked from invoice rows. Granting the page
        // perm directly (instead of piggy-backing on View:Invoice) keeps the
        // access model honest; the live-AADE orphan lookup inside the page is
        // separately gated on View:MyDataConsole (admin-only).
        'MyDataMarkDetail' => ['View'],
    ];

    /**
     * Resources a company_admin must NOT hold permissions for — they are
     * panel-global (`$isScopedToTenant=false`) or escalation-sensitive, so
     * granting them to a per-tenant admin would leak/allow cross-tenant
     * management. Default-deny: a new sensitive resource is excluded until
     * deliberately removed from this list.
     *
     * @var list<string>
     */
    public const ADMIN_FORBIDDEN_RESOURCES = ['User', 'Company', 'Role'];

    // ── Team-scoped envelope ───────────────────────────────────────────────

    /**
     * Run $fn with the registrar's team pinned to $company, restoring the
     * previous team afterwards. WRITES pass $flushCache=true to bust the
     * permission cache on entry + exit; READS (role-name lookups) pass false —
     * they only need the team switch + the caller's own unsetRelation('roles'),
     * so they don't thrash the global permission cache (important: the role
     * badge columns call the reads once PER ROW).
     *
     * @template T
     *
     * @param  Closure(PermissionRegistrar): T  $fn
     * @return T
     */
    private function withTeam(Company $company, Closure $fn, bool $flushCache = true): mixed
    {
        $registrar = app(PermissionRegistrar::class);
        $previousTeam = $registrar->getPermissionsTeamId();
        $registrar->setPermissionsTeamId($company->getKey());

        if ($flushCache) {
            $registrar->forgetCachedPermissions();
        }

        try {
            return $fn($registrar);
        } finally {
            $registrar->setPermissionsTeamId($previousTeam);
            if ($flushCache) {
                $registrar->forgetCachedPermissions();
            }
        }
    }

    // ── super_admin ────────────────────────────────────────────────────────

    /**
     * Ensure the given company's team has a `super_admin` role row.
     * Idempotent. Returns the role.
     */
    public function ensureSuperAdminRole(Company $company): Role
    {
        return $this->withTeam($company, fn (): Role => Role::query()->firstOrCreate([
            'name' => ShieldUtils::getSuperAdminName(),
            'guard_name' => ShieldUtils::getFilamentAuthGuard(),
            'company_id' => $company->getKey(),
        ]));
    }

    /**
     * Assign the company's super_admin role to a user (creating the role if
     * needed). Idempotent. The caller decides eligibility — we do NOT
     * blanket-grant super_admin to every attached user.
     */
    public function assignSuperAdmin(User $user, Company $company): void
    {
        $role = $this->ensureSuperAdminRole($company);

        $this->withTeam($company, function () use ($user, $role): void {
            // Drop any roles relation cached under a different team, else the
            // hasRole() guard reads stale data across team switches.
            $user->unsetRelation('roles');
            if (! $user->hasRole($role)) {
                $user->assignRole($role);
            }
        });
    }

    /**
     * Is the user a super_admin in ANY of their teams? Used to decide whether
     * a newly-attached company should inherit the super_admin grant.
     */
    public function isSuperAdminAnywhere(User $user): bool
    {
        $superName = ShieldUtils::getSuperAdminName();

        foreach ($user->companies as $company) {
            // Read-only role check per team — no cache flush needed.
            $hasIt = $this->withTeam($company, function () use ($user, $superName): bool {
                $user->unsetRelation('roles');

                return $user->hasRole($superName);
            }, flushCache: false);

            if ($hasIt) {
                return true;
            }
        }

        return false;
    }

    /**
     * Does the user hold super_admin specifically within THIS company's team?
     * (Distinct from isSuperAdminAnywhere — drives the role-picker escalation
     * guard: only a super_admin in the target company may grant or remove it.)
     */
    public function hasSuperAdminIn(User $user, Company $company): bool
    {
        return $this->withTeam($company, function () use ($user): bool {
            $user->unsetRelation('roles');

            return $user->hasRole(ShieldUtils::getSuperAdminName());
        }, flushCache: false);
    }

    // ── Standard non-super roles (company_admin, operator) ─────────────────

    /**
     * Ensure a tenant has the standard non-super roles WITH their permission
     * sets attached. This is the PROVISIONING path (CompanyObserver, seeder,
     * backfill command) — it re-syncs the permission maps, so it's also how you
     * refresh roles after shield:generate adds new permissions. Idempotent.
     *
     * NB: re-syncs permissions, so it must NOT be on the per-assignment hot path
     * (the role picker uses ensureManagedRolesExist instead — rows only).
     */
    public function ensureStandardRoles(Company $company): void
    {
        $this->withTeam($company, function () use ($company): void {
            $guard = ShieldUtils::getFilamentAuthGuard();

            $admin = $this->firstOrCreateRole(self::ROLE_COMPANY_ADMIN, $guard, $company);
            $admin->syncPermissions($this->companyAdminPermissions($guard));

            $operator = $this->firstOrCreateRole(self::ROLE_OPERATOR, $guard, $company);
            $operator->syncPermissions($this->operatorPermissions($guard));
        });
    }

    /**
     * Ensure the managed role ROWS exist for a team WITHOUT re-syncing their
     * permission maps — used before assigning a role so we never block on a
     * missing row, but don't do a full permission re-sync on every picker save
     * (that would also clobber any manual per-tenant role customization).
     */
    private function ensureManagedRolesExist(Company $company): void
    {
        $this->withTeam($company, function () use ($company): void {
            $guard = ShieldUtils::getFilamentAuthGuard();
            foreach ($this->managedRoleNames() as $name) {
                $this->firstOrCreateRole($name, $guard, $company);
            }
        });
    }

    private function firstOrCreateRole(string $name, string $guard, Company $company): Role
    {
        return Role::query()->firstOrCreate([
            'name' => $name,
            'guard_name' => $guard,
            'company_id' => $company->getKey(),
        ]);
    }

    /**
     * The global Permission rows the OPERATOR role should hold, from
     * OPERATOR_PERMISSION_MAP, filtered to those that actually exist.
     *
     * @return Collection<int, Permission>
     */
    private function operatorPermissions(string $guard): Collection
    {
        $wanted = [];
        foreach (self::OPERATOR_PERMISSION_MAP as $resource => $actions) {
            foreach ($actions as $action) {
                $wanted[] = "{$action}:{$resource}";
            }
        }

        return Permission::query()
            ->where('guard_name', $guard)
            ->whereIn('name', $wanted)
            ->get();
    }

    /**
     * The global Permission rows the COMPANY_ADMIN role should hold: every
     * permission of the guard EXCEPT those whose resource is in
     * ADMIN_FORBIDDEN_RESOURCES (User/Company/Role).
     *
     * @return Collection<int, Permission>
     */
    private function companyAdminPermissions(string $guard): Collection
    {
        return Permission::query()
            ->where('guard_name', $guard)
            ->get()
            ->reject(fn (Permission $p): bool => in_array(
                Str::after($p->name, ':'),
                self::ADMIN_FORBIDDEN_RESOURCES,
                true,
            ));
    }

    /**
     * Assign a standard role (company_admin|operator) to a user within a team.
     * Additive — does NOT strip other roles (use setRoleInCompany for picker
     * semantics). Ensures the role rows exist first.
     */
    public function assignStandardRole(User $user, Company $company, string $roleName): void
    {
        if (! in_array($roleName, [self::ROLE_COMPANY_ADMIN, self::ROLE_OPERATOR], true)) {
            throw new \InvalidArgumentException("Unknown standard role: {$roleName}");
        }

        $this->ensureManagedRolesExist($company);

        $this->withTeam($company, function () use ($user, $roleName): void {
            $user->unsetRelation('roles');
            if (! $user->hasRole($roleName)) {
                $user->assignRole($roleName);
            }
        });
    }

    // ── Role picker (per user × company) ───────────────────────────────────
    //
    // The role-picker treats each (user, company) pivot as holding AT MOST ONE
    // managed role: super_admin | company_admin | operator. These helpers read
    // and set that single role within a team.

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
     * Which managed role (if any) the user holds in the company's team — the
     * highest-privilege one if somehow more than one is present, else null.
     */
    public function roleInCompany(User $user, Company $company): ?string
    {
        return $this->withTeam($company, function () use ($user): ?string {
            $user->unsetRelation('roles');

            foreach ($this->managedRoleNames() as $name) {
                if ($user->hasRole($name)) {
                    return $name;
                }
            }

            return null;
        }, flushCache: false);
    }

    /**
     * Set the user's single managed role within a company's team (picker
     * semantics): strips any other managed role they hold there first, then
     * assigns the chosen one. Pass null to clear all managed roles. Ensures the
     * role rows exist first (rows only — does not re-sync permission maps).
     *
     * Does NOT enforce escalation rules — the caller (UI action) decides whether
     * the acting user may grant OR remove super_admin. Non-managed roles are
     * left untouched.
     */
    public function setRoleInCompany(User $user, Company $company, ?string $roleName): void
    {
        $managed = $this->managedRoleNames();

        if ($roleName !== null && ! in_array($roleName, $managed, true)) {
            throw new \InvalidArgumentException("Unknown managed role: {$roleName}");
        }

        $this->ensureManagedRolesExist($company);

        $this->withTeam($company, function () use ($user, $roleName, $managed): void {
            $user->unsetRelation('roles');

            foreach ($managed as $name) {
                if ($name !== $roleName && $user->hasRole($name)) {
                    $user->removeRole($name);
                }
            }

            if ($roleName !== null && ! $user->hasRole($roleName)) {
                $user->assignRole($roleName);
            }
        });
    }
}
