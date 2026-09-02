<?php

namespace App\Services;

use App\Models\Company;
use App\Models\Role;
use App\Models\User;
use BezhanSalleh\FilamentShield\Support\Utils as ShieldUtils;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

/**
 * Provisions + maintains the per-tenant managed roles under Shield's teams mode.
 *
 * Background: Shield runs in teams mode (`team_foreign_key=company_id`), so a
 * role row exists PER company. The super-admin bypass in AppServiceProvider is
 * `Gate::before(fn => $user->isSystemSuperAdmin())` — GLOBAL: a user who holds
 * super_admin in ANY tenant (and is still a member of it) bypasses every policy
 * everywhere. company_admin/operator stay per-tenant. This service is the single
 * place that creates/repairs roles and assigns them, called from the
 * CompanyObserver, the DatabaseSeeder, the `shield:sync-super-admin` backfill
 * command, the importer, and the role-picker UI.
 *
 * Three managed roles per tenant:
 *   - super_admin   : Gate::before bypass (global); needs no permissions.
 *   - company_admin : every permission of THIS tenant EXCEPT the cross-tenant /
 *                     platform-level resources in ADMIN_FORBIDDEN_RESOURCES
 *                     (User/Company/Role) — so a tenant admin can't reach the
 *                     panel-global user/company roster or escalate roles.
 *   - operator      : the curated daily-work subset (OPERATOR_PERMISSION_MAP).
 *
 * Teams-mode discipline: every role read AND write goes through RAW queries with
 * the EXPLICIT target company_id (findRole/userHoldsRole/isSystemSuperAdmin/
 * upsertRole/attachRole/detachRole) — NEVER spatie's registrar-team-aware
 * Eloquent, which writes/reads the AMBIENT panel tenant and would land rows under
 * the wrong company (the long «collided»/«stripped menu» saga).
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
        // Leads (mini-CRM): the «κυνηγός» IS an operator — no separate role
        // (owner decision, docs/leads-mini-crm.md §9).
        'Lead' => ['ViewAny', 'View', 'Create', 'Update'],
        // …and the two alternative views of the same leads (kanban / calendar).
        'LeadsBoard' => ['View'],
        'LeadsCalendar' => ['View'],
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
     * These are EXACTLY the three `$isScopedToTenant = false` resources in the
     * panel (User / Company / UpdateRun) plus Role (escalation). UpdateRun is
     * the whole-app deploy history: cross-tenant AND immutable, so a company
     * admin has no business holding `*:UpdateRun` at all — `UpdateRunPolicy`
     * denies them a second time at the Gate.
     *
     * @var list<string>
     */
    public const ADMIN_FORBIDDEN_RESOURCES = ['User', 'Company', 'Role', 'UpdateRun'];

    // ── super_admin ────────────────────────────────────────────────────────

    /**
     * Ensure the given company's team has a `super_admin` role row.
     * Idempotent. Returns the role.
     */
    public function ensureSuperAdminRole(Company $company): Role
    {
        return $this->upsertRole(ShieldUtils::getSuperAdminName(), ShieldUtils::getFilamentAuthGuard(), $company);
    }

    /**
     * Assign the company's super_admin role to a user (creating the role if
     * needed). Idempotent. The caller decides eligibility — we do NOT
     * blanket-grant super_admin to every attached user.
     */
    public function assignSuperAdmin(User $user, Company $company): void
    {
        $role = $this->ensureSuperAdminRole($company);
        // RAW attach (idempotent) — see attachRole: spatie's assignRole would
        // write the wrong team under the ambient panel tenant.
        $this->attachRole($user, $role, (int) $company->getKey());
    }

    /**
     * Is the user a super_admin in ANY of their teams? Used to decide whether
     * a newly-attached company should inherit the super_admin grant.
     */
    public function isSuperAdminAnywhere(User $user): bool
    {
        return $this->isSystemSuperAdmin($user);
    }

    /**
     * Is this user a SYSTEM super_admin — i.e. holds the super_admin role in ANY
     * tenant? Single raw query (no team filter), safe to call from Gate::before.
     * A system super_admin is the OPERATOR: they bypass every policy in every
     * tenant (the global Gate::before bypass), unlike a per-tenant company_admin.
     */
    public function isSystemSuperAdmin(User $user): bool
    {
        $tables = (array) config('permission.table_names');
        $cols = (array) config('permission.column_names');
        $teamKey = $cols['team_foreign_key'] ?? 'company_id';

        return DB::table(($tables['model_has_roles'] ?? 'model_has_roles').' as mhr')
            ->join(($tables['roles'] ?? 'roles').' as r', 'r.id', '=', 'mhr.'.($cols['role_pivot_key'] ?? 'role_id'))
            // Require the user to STILL be a member of that company. Detaching a
            // user removes the company_user pivot but NOT their role assignment,
            // so without this a detached super_admin would keep the global bypass
            // (a privilege-non-revocation hole). Membership-gated = detach revokes.
            ->join('company_user as cu', function ($join) use ($user, $teamKey): void {
                $join->on('cu.company_id', '=', "r.{$teamKey}")
                    ->where('cu.user_id', '=', $user->getKey());
            })
            ->where('r.name', ShieldUtils::getSuperAdminName())
            ->where('r.guard_name', ShieldUtils::getFilamentAuthGuard())
            ->where('mhr.'.($cols['model_morph_key'] ?? 'model_id'), $user->getKey())
            ->where('mhr.model_type', $user->getMorphClass())
            ->exists();
    }

    /**
     * Does the user hold super_admin specifically within THIS company's team?
     * (Distinct from isSuperAdminAnywhere — drives the role-picker escalation
     * guard: only a super_admin in the target company may grant or remove it.)
     */
    public function hasSuperAdminIn(User $user, Company $company): bool
    {
        return $this->userHoldsRole($user, $company, ShieldUtils::getSuperAdminName());
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
        $guard = ShieldUtils::getFilamentAuthGuard();

        // No team envelope needed: upsertRole writes the role row RAW with the
        // explicit company_id, and role_has_permissions has no team column, so
        // syncPermissions is team-agnostic.
        $admin = $this->upsertRole(self::ROLE_COMPANY_ADMIN, $guard, $company);
        $admin->syncPermissions($this->companyAdminPermissions($guard));

        $operator = $this->upsertRole(self::ROLE_OPERATOR, $guard, $company);
        $operator->syncPermissions($this->operatorPermissions($guard));
    }

    /**
     * Ensure the managed role ROWS exist for a team WITHOUT re-syncing their
     * permission maps — used before assigning a role so we never block on a
     * missing row, but don't do a full permission re-sync on every picker save
     * (that would also clobber any manual per-tenant role customization). Public
     * so an --into import can HEAL a roles-less company without the clobber.
     */
    public function ensureManagedRolesExist(Company $company): void
    {
        $guard = ShieldUtils::getFilamentAuthGuard();
        foreach ($this->managedRoleNames() as $name) {
            $role = $this->upsertRole($name, $guard, $company);

            // super_admin needs NO permissions (the global Gate::before bypass).
            if ($name === ShieldUtils::getSuperAdminName()) {
                continue;
            }

            // Backfill the baseline permission map ONLY when the role currently
            // holds NONE — a freshly created (import `--into` heal) row, or one a
            // picker save created rows-only before any permission sync ran. A role
            // that already holds ≥1 permission is left untouched (never clobber a
            // manual per-tenant customization). Without this, assigning
            // company_admin/operator via the picker to a company whose roles were
            // never permission-synced grants an EMPTY role → the user sees the
            // tenant but no resources (the «βλέπει Nexon αλλά τίποτα μέσα» case).
            if ($role->permissions()->count() === 0) {
                $role->syncPermissions($this->defaultPermissionsFor($name, $guard));
            }
        }
    }

    /**
     * THE canonical permission set a managed non-super role should hold —
     * the single definition behind role provisioning, the empty-role backfill
     * and `roles:reprovision`. super_admin is intentionally absent (it holds
     * none: the global Gate::before bypass covers it).
     *
     * @return Collection<int, Permission>
     */
    public function defaultPermissionsFor(string $name, ?string $guard = null): Collection
    {
        $guard ??= ShieldUtils::getFilamentAuthGuard();

        return match ($name) {
            self::ROLE_COMPANY_ADMIN => $this->companyAdminPermissions($guard),
            self::ROLE_OPERATOR => $this->operatorPermissions($guard),
            default => collect(),
        };
    }

    /**
     * The managed role row of a company, or null when it does not exist yet.
     * Public so `roles:reprovision` can diff a role's permissions without
     * creating anything.
     */
    public function findManagedRole(string $name, Company $company): ?Role
    {
        return $this->findRole($name, ShieldUtils::getFilamentAuthGuard(), (int) $company->getKey());
    }

    /**
     * Idempotent role upsert under teams mode. Plain `firstOrCreate` can still
     * raise a 1062 duplicate — a find/create race, or (on MariaDB) the
     * transaction's snapshot not yet seeing a row the (company_id, name,
     * guard_name) unique index already rejects. So on a unique violation we ADOPT
     * the existing row instead of failing the whole flow (e.g. an import
     * provisioning a company id whose role already exists). "ensure…" must never
     * throw on an already-present role.
     */
    private function upsertRole(string $name, string $guard, Company $company): Role
    {
        $companyId = (int) $company->getKey();

        if ($role = $this->findRole($name, $guard, $companyId)) {
            return $role;
        }

        try {
            // RAW insert — NOT Role::query()->create(): Eloquent's create fires
            // spatie's teams `creating` hook, which OVERRIDES company_id with the
            // registrar's CURRENT team (the ambient panel tenant), so the role is
            // written under the WRONG company → a 1062 collision (the whole
            // import/picker «collided but could not be re-read» saga: $company is
            // 81 but the INSERT used the panel tenant 4). A raw insert writes the
            // company_id we pass, verbatim.
            $tables = (array) config('permission.table_names');
            $cols = (array) config('permission.column_names');
            $id = DB::table($tables['roles'] ?? 'roles')->insertGetId([
                'name' => $name,
                'guard_name' => $guard,
                ($cols['team_foreign_key'] ?? 'company_id') => $companyId,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            app(PermissionRegistrar::class)->forgetCachedPermissions();

            return Role::query()->withoutGlobalScopes()->findOrFail($id);
        } catch (UniqueConstraintViolationException $e) {
            // The row exists despite the lookup missing it — adopt it.
            return $this->findRole($name, $guard, $companyId) ?? throw new RuntimeException(
                "Role «{$name}» for company {$companyId} collided but could not be re-read.",
                previous: $e,
            );
        }
    }

    /**
     * Look up a role by its (company_id, name, guard_name) unique key via RAW
     * DB::table — bypassing spatie's teams-aware Eloquent query. That query, with
     * the registrar's team state, can MISS a row the unique index still rejects,
     * producing a 1062 on the follow-up insert that surfaced as «… collided but
     * could not be re-read» on import provisioning AND on attach-user. The raw
     * lookup can't miss it; we hydrate the Eloquent model (no global scopes) for
     * the caller.
     */
    private function findRole(string $name, string $guard, int $companyId): ?Role
    {
        $tables = (array) config('permission.table_names');
        $cols = (array) config('permission.column_names');
        $id = DB::table($tables['roles'] ?? 'roles')
            ->where('name', $name)
            ->where('guard_name', $guard)
            ->where(($cols['team_foreign_key'] ?? 'company_id'), $companyId)
            ->value('id');

        return $id !== null ? Role::query()->withoutGlobalScopes()->find($id) : null;
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
     * NB: the resource is the part after the first ':'. A permission name with
     * NO ':' (a non-Shield custom/global ability — none exist today,
     * `custom_permissions` is empty) has no resource to match, so it would be
     * GRANTED. If a cross-tenant-sensitive custom permission is ever added, give
     * it a `:Resource` suffix in the forbidden list or this filter won't catch it.
     *
     * @return Collection<int, Permission>
     */
    private function companyAdminPermissions(string $guard): Collection
    {
        return Permission::query()
            ->where('guard_name', $guard)
            ->get()
            ->reject(fn (Permission $p): bool => str_contains($p->name, ':')
                && in_array(Str::after($p->name, ':'), self::ADMIN_FORBIDDEN_RESOURCES, true));
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

        $role = $this->upsertRole($roleName, ShieldUtils::getFilamentAuthGuard(), $company);
        $this->attachRole($user, $role, (int) $company->getKey());
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
        // Raw pivot reads — no spatie teams resolution, no cache, no team switch.
        foreach ($this->managedRoleNames() as $name) {
            if ($this->userHoldsRole($user, $company, $name)) {
                return $name;
            }
        }

        return null;
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
        $guard = ShieldUtils::getFilamentAuthGuard();
        $companyId = (int) $company->getKey();

        // Strip any OTHER managed role, then attach the chosen one — all via RAW
        // model_has_roles writes (attachRole/detachRole), NOT spatie's
        // assignRole/removeRole which write the team column from the registrar's
        // CURRENT team (the ambient panel tenant), landing the row under the WRONG
        // company when managing a non-current tenant.
        foreach ($managed as $name) {
            if ($name === $roleName) {
                continue;
            }
            $role = $this->findRole($name, $guard, $companyId);
            if ($role !== null) {
                $this->detachRole($user, $role, $companyId);
            }
        }

        if ($roleName !== null) {
            $role = $this->findRole($roleName, $guard, $companyId);
            if ($role !== null) {
                $this->attachRole($user, $role, $companyId);
            }
        }
    }

    /**
     * Attach a role to a user in a company's team via RAW model_has_roles —
     * idempotent (insertOrIgnore on the composite PK). NOT spatie's assignRole,
     * which writes the team column from the registrar's CURRENT team (the ambient
     * panel tenant), so assigning a role in a NON-current tenant would land it
     * under the WRONG company (the same wrong-team bug the role INSERT had).
     */
    private function attachRole(User $user, Role $role, int $companyId): void
    {
        $tables = (array) config('permission.table_names');
        $cols = (array) config('permission.column_names');

        DB::table($tables['model_has_roles'] ?? 'model_has_roles')->insertOrIgnore([
            ($cols['role_pivot_key'] ?? 'role_id') => $role->getKey(),
            ($cols['model_morph_key'] ?? 'model_id') => $user->getKey(),
            'model_type' => $user->getMorphClass(),
            ($cols['team_foreign_key'] ?? 'company_id') => $companyId,
        ]);
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    /** Detach a role from a user in a company's team via RAW model_has_roles. */
    private function detachRole(User $user, Role $role, int $companyId): void
    {
        $tables = (array) config('permission.table_names');
        $cols = (array) config('permission.column_names');

        DB::table($tables['model_has_roles'] ?? 'model_has_roles')
            ->where(($cols['role_pivot_key'] ?? 'role_id'), $role->getKey())
            ->where(($cols['model_morph_key'] ?? 'model_id'), $user->getKey())
            ->where('model_type', $user->getMorphClass())
            ->where(($cols['team_foreign_key'] ?? 'company_id'), $companyId)
            ->delete();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    /**
     * Does the user hold the named role in the company's team? Checked via a RAW
     * join on model_has_roles → roles — bypassing spatie's teams-aware relation
     * (the same unreliability the findRole() write fix avoids). No team context
     * or permission cache involved; it reads the pivot directly.
     */
    public function userHoldsRole(User $user, Company $company, string $name): bool
    {
        $tables = (array) config('permission.table_names');
        $cols = (array) config('permission.column_names');
        $roles = $tables['roles'] ?? 'roles';
        $pivot = $tables['model_has_roles'] ?? 'model_has_roles';
        $rolePivotKey = $cols['role_pivot_key'] ?? 'role_id';
        $morphKey = $cols['model_morph_key'] ?? 'model_id';
        // team key is company_id across ekdosi (the locked tenant decision).
        $teamKey = $cols['team_foreign_key'] ?? 'company_id';

        return DB::table("{$pivot} as mhr")
            ->join("{$roles} as r", 'r.id', '=', "mhr.{$rolePivotKey}")
            ->where('r.name', $name)
            ->where('r.guard_name', ShieldUtils::getFilamentAuthGuard())
            ->where("r.{$teamKey}", $company->getKey())
            ->where("mhr.{$morphKey}", $user->getKey())
            ->where('mhr.model_type', $user->getMorphClass())
            ->where("mhr.{$teamKey}", $company->getKey())
            ->exists();
    }
}
