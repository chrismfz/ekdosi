<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Role;
use App\Models\User;
use App\Services\TenantRoleProvisioner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Covers the standard non-super roles (company_admin, operator) added on top
 * of the per-tenant super_admin. Verifies the permission maps, idempotency,
 * team-scoped assignment, and that operator is a strict subset of admin.
 */
class TenantStandardRolesTest extends TestCase
{
    use RefreshDatabase;

    private string $guard = 'web';

    private function makeCompany(string $slug): Company
    {
        return Company::create([
            'name' => $slug, 'slug' => $slug.'-'.uniqid(),
            'country_code' => 'GR', 'einvoice_provider' => 'gr-mydata', 'mydata_mode' => 'off',
        ]);
    }

    /**
     * Seed a representative slice of the GLOBAL Shield permissions that
     * shield:generate would create — enough to prove the maps select correctly.
     */
    private function seedPermissions(): void
    {
        $names = [
            // operator-eligible
            'ViewAny:Invoice', 'View:Invoice', 'Create:Invoice', 'Update:Invoice', 'Delete:Invoice',
            'ViewAny:Customer', 'Create:Customer',
            'ViewAny:Quote', 'Create:Quote',
            // NOT operator-eligible (Setup / users / company)
            'ViewAny:VatCategory', 'Create:VatCategory',
            'ViewAny:User', 'Update:User',
            'ViewAny:Company',
        ];
        foreach ($names as $n) {
            Permission::findOrCreate($n, $this->guard);
        }
    }

    private function permNames(Role $role): array
    {
        return $role->permissions->pluck('name')->sort()->values()->all();
    }

    public function test_creates_company_admin_and_operator_roles(): void
    {
        $this->seedPermissions();
        $company = $this->makeCompany('roles');

        // CompanyObserver already called ensureStandardRoles on create, but the
        // permissions were seeded after — re-run to attach them.
        app(TenantRoleProvisioner::class)->ensureStandardRoles($company);

        app(PermissionRegistrar::class)->setPermissionsTeamId($company->getKey());

        foreach ([TenantRoleProvisioner::ROLE_COMPANY_ADMIN, TenantRoleProvisioner::ROLE_OPERATOR] as $name) {
            $this->assertTrue(
                Role::query()->where('name', $name)->where('company_id', $company->getKey())->exists(),
                "role {$name} should exist for the tenant"
            );
        }
    }

    public function test_company_admin_gets_all_but_forbidden_operator_gets_curated_subset(): void
    {
        $this->seedPermissions();
        $company = $this->makeCompany('maps');
        app(TenantRoleProvisioner::class)->ensureStandardRoles($company);

        $registrar = app(PermissionRegistrar::class);
        $registrar->setPermissionsTeamId($company->getKey());
        $registrar->forgetCachedPermissions();

        $admin = Role::where('name', TenantRoleProvisioner::ROLE_COMPANY_ADMIN)->where('company_id', $company->getKey())->first();
        $operator = Role::where('name', TenantRoleProvisioner::ROLE_OPERATOR)->where('company_id', $company->getKey())->first();

        // company_admin = every permission EXCEPT the cross-tenant/platform ones
        // (User / Company / Role — ADMIN_FORBIDDEN_RESOURCES).
        $adminPerms = $this->permNames($admin);
        $this->assertContains('ViewAny:Invoice', $adminPerms);
        $this->assertContains('ViewAny:VatCategory', $adminPerms, 'admin keeps Setup lookups');
        $this->assertNotContains('ViewAny:User', $adminPerms, 'admin must NOT manage the panel-global user roster');
        $this->assertNotContains('Update:User', $adminPerms);
        $this->assertNotContains('ViewAny:Company', $adminPerms, 'admin must NOT reach cross-tenant Companies');

        $opPerms = $this->permNames($operator);
        // Operator HAS the curated invoice/customer/quote view+create+update.
        $this->assertContains('ViewAny:Invoice', $opPerms);
        $this->assertContains('Create:Invoice', $opPerms);
        $this->assertContains('Update:Invoice', $opPerms);
        $this->assertContains('Create:Customer', $opPerms);
        $this->assertContains('Create:Quote', $opPerms);
        // Operator does NOT get delete, Setup, users, or company.
        $this->assertNotContains('Delete:Invoice', $opPerms);
        $this->assertNotContains('ViewAny:VatCategory', $opPerms);
        $this->assertNotContains('ViewAny:User', $opPerms);
        $this->assertNotContains('ViewAny:Company', $opPerms);

        // Operator is a strict subset of company_admin.
        $this->assertEmpty(array_diff($opPerms, $adminPerms), 'operator perms must be a subset of admin perms');
        $this->assertNotEmpty(array_diff($adminPerms, $opPerms), 'admin must have more than operator');
    }

    public function test_ensure_standard_roles_is_idempotent(): void
    {
        $this->seedPermissions();
        $company = $this->makeCompany('idem');
        $p = app(TenantRoleProvisioner::class);

        $p->ensureStandardRoles($company);
        $p->ensureStandardRoles($company);

        $this->assertSame(1, Role::where('name', TenantRoleProvisioner::ROLE_OPERATOR)->where('company_id', $company->getKey())->count());
        $this->assertSame(1, Role::where('name', TenantRoleProvisioner::ROLE_COMPANY_ADMIN)->where('company_id', $company->getKey())->count());
    }

    public function test_assign_standard_role_is_team_scoped(): void
    {
        $this->seedPermissions();
        $a = $this->makeCompany('a');
        $b = $this->makeCompany('b');

        $user = User::create(['name' => 'Op', 'email' => 'op-'.uniqid().'@test.local', 'password' => bcrypt('x')]);
        $user->companies()->attach([$a->id, $b->id]);

        app(TenantRoleProvisioner::class)->assignStandardRole($user, $a, TenantRoleProvisioner::ROLE_OPERATOR);

        $registrar = app(PermissionRegistrar::class);

        $registrar->setPermissionsTeamId($a->getKey());
        $registrar->forgetCachedPermissions();
        $user->unsetRelation('roles');
        $this->assertTrue($user->hasRole(TenantRoleProvisioner::ROLE_OPERATOR), 'operator in A');

        $registrar->setPermissionsTeamId($b->getKey());
        $registrar->forgetCachedPermissions();
        $user->unsetRelation('roles');
        $this->assertFalse($user->hasRole(TenantRoleProvisioner::ROLE_OPERATOR), 'NOT operator in B');
    }

    public function test_assign_rejects_unknown_role(): void
    {
        $company = $this->makeCompany('bad');
        $user = User::create(['name' => 'U', 'email' => 'u-'.uniqid().'@test.local', 'password' => bcrypt('x')]);
        $user->companies()->attach($company->id);

        $this->expectException(\InvalidArgumentException::class);
        app(TenantRoleProvisioner::class)->assignStandardRole($user, $company, 'super_admin');
    }
}
