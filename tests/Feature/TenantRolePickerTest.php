<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\User;
use App\Services\TenantRoleProvisioner;
use BezhanSalleh\FilamentShield\Support\Utils as ShieldUtils;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Covers the per-tenant role-picker primitives (PR2) on TenantRoleProvisioner:
 * roleInCompany / setRoleInCompany / hasSuperAdminIn. Picker semantics = exactly
 * one managed role per (user, company) team, replaced on change, team-scoped.
 */
class TenantRolePickerTest extends TestCase
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

    private function makeUser(): User
    {
        return User::create([
            'name' => 'U', 'email' => 'u-'.uniqid().'@test.local', 'password' => bcrypt('x'),
        ]);
    }

    private function seedPermissions(): void
    {
        foreach ([
            'ViewAny:Invoice', 'Create:Invoice', 'Update:Invoice',
            'ViewAny:Customer', 'Create:Customer',
            'ViewAny:VatCategory', 'ViewAny:User',
        ] as $n) {
            Permission::findOrCreate($n, $this->guard);
        }
    }

    public function test_set_and_read_role_in_company(): void
    {
        $this->seedPermissions();
        $company = $this->makeCompany('pick');
        $user = $this->makeUser();
        $user->companies()->attach($company->id);

        $p = app(TenantRoleProvisioner::class);

        $this->assertNull($p->roleInCompany($user, $company), 'no role initially');

        $p->setRoleInCompany($user, $company, TenantRoleProvisioner::ROLE_OPERATOR);
        $this->assertSame(TenantRoleProvisioner::ROLE_OPERATOR, $p->roleInCompany($user, $company));
    }

    public function test_system_super_admin_can_bootstrap_a_role_in_a_company_without_one(): void
    {
        $this->seedPermissions();
        $owned = $this->makeCompany('owned');
        $restored = $this->makeCompany('restored');   // owner holds NO role here yet
        $owner = $this->makeUser();
        $owner->companies()->attach([$owned->id, $restored->id]);

        $p = app(TenantRoleProvisioner::class);
        $p->assignSuperAdmin($owner, $owned);   // super_admin in ONE company only

        // The role-picker gate (actorMayManageRoles) keys on this: a system
        // super_admin may manage roles in EVERY company, so they can give
        // themselves a role in a freshly restored tenant they have no role in.
        $this->assertTrue($p->isSuperAdminAnywhere($owner));
        $this->assertFalse($p->hasSuperAdminIn($owner, $restored), 'no role in the restored company yet');

        $p->setRoleInCompany($owner, $restored, ShieldUtils::getSuperAdminName());
        $this->assertSame(ShieldUtils::getSuperAdminName(), $p->roleInCompany($owner, $restored));
    }

    public function test_role_assignment_lands_in_the_target_company_not_the_ambient_team(): void
    {
        $this->seedPermissions();
        $ambient = $this->makeCompany('ambient');   // the open panel tenant
        $target = $this->makeCompany('target');      // the company being managed
        $user = $this->makeUser();
        $user->companies()->attach([$ambient->id, $target->id]);

        // Simulate the panel: the registrar team is the CURRENT tenant (ambient),
        // NOT the company we're assigning a role in.
        app(PermissionRegistrar::class)->setPermissionsTeamId($ambient->id);

        app(TenantRoleProvisioner::class)->setRoleInCompany($user, $target, TenantRoleProvisioner::ROLE_OPERATOR);

        // The pivot row must be written under TARGET (raw, explicit company_id) —
        // not the ambient team a spatie write would have used.
        $this->assertTrue(DB::table('model_has_roles')
            ->where('model_id', $user->id)->where('company_id', $target->id)->exists(),
            'operator role assigned under the TARGET company');
        $this->assertFalse(DB::table('model_has_roles')
            ->where('model_id', $user->id)->where('company_id', $ambient->id)->exists(),
            'nothing leaked into the ambient team');
    }

    public function test_picker_replaces_previous_role(): void
    {
        $this->seedPermissions();
        $company = $this->makeCompany('replace');
        $user = $this->makeUser();
        $user->companies()->attach($company->id);

        $p = app(TenantRoleProvisioner::class);

        $p->setRoleInCompany($user, $company, TenantRoleProvisioner::ROLE_OPERATOR);
        $p->setRoleInCompany($user, $company, TenantRoleProvisioner::ROLE_COMPANY_ADMIN);

        // Exactly one managed role remains — the new one.
        $this->assertSame(TenantRoleProvisioner::ROLE_COMPANY_ADMIN, $p->roleInCompany($user, $company));

        app(PermissionRegistrar::class)->setPermissionsTeamId($company->getKey());
        $user->unsetRelation('roles');
        $this->assertFalse($user->hasRole(TenantRoleProvisioner::ROLE_OPERATOR), 'old role stripped');
    }

    public function test_null_clears_role(): void
    {
        $this->seedPermissions();
        $company = $this->makeCompany('clear');
        $user = $this->makeUser();
        $user->companies()->attach($company->id);

        $p = app(TenantRoleProvisioner::class);
        $p->setRoleInCompany($user, $company, TenantRoleProvisioner::ROLE_OPERATOR);
        $p->setRoleInCompany($user, $company, null);

        $this->assertNull($p->roleInCompany($user, $company));
    }

    public function test_role_is_team_scoped(): void
    {
        $this->seedPermissions();
        $a = $this->makeCompany('a');
        $b = $this->makeCompany('b');
        $user = $this->makeUser();
        $user->companies()->attach([$a->id, $b->id]);

        $p = app(TenantRoleProvisioner::class);
        $p->setRoleInCompany($user, $a, TenantRoleProvisioner::ROLE_COMPANY_ADMIN);
        $p->setRoleInCompany($user, $b, TenantRoleProvisioner::ROLE_OPERATOR);

        // Same user, two companies, two different roles — the whole point.
        $this->assertSame(TenantRoleProvisioner::ROLE_COMPANY_ADMIN, $p->roleInCompany($user, $a));
        $this->assertSame(TenantRoleProvisioner::ROLE_OPERATOR, $p->roleInCompany($user, $b));
    }

    public function test_super_admin_via_picker_and_has_super_admin_in(): void
    {
        $this->seedPermissions();
        $a = $this->makeCompany('super-a');
        $b = $this->makeCompany('super-b');
        $user = $this->makeUser();
        $user->companies()->attach([$a->id, $b->id]);

        $p = app(TenantRoleProvisioner::class);
        $p->setRoleInCompany($user, $a, ShieldUtils::getSuperAdminName());

        $this->assertSame(ShieldUtils::getSuperAdminName(), $p->roleInCompany($user, $a));
        $this->assertTrue($p->hasSuperAdminIn($user, $a), 'super_admin in A');
        $this->assertFalse($p->hasSuperAdminIn($user, $b), 'NOT super_admin in B');
    }

    public function test_picker_backfills_permissions_for_an_empty_managed_role(): void
    {
        // Company created BEFORE any Permission rows exist (the order a fresh box
        // / an import `--into` heal hits): the provisioner makes the company_admin
        // ROW but has nothing to sync, so it lands with ZERO permissions — the
        // «βλέπει την εταιρία αλλά τίποτα μέσα» state.
        $company = $this->makeCompany('healme');
        $user = $this->makeUser();
        $user->companies()->attach($company->id);

        // Shield permissions appear afterwards (shield:generate runs).
        $this->seedPermissions();

        $role = \App\Models\Role::query()->withoutGlobalScopes()
            ->where('name', TenantRoleProvisioner::ROLE_COMPANY_ADMIN)
            ->where('company_id', $company->getKey())
            ->first();
        $this->assertNotNull($role, 'company_admin row exists');
        $this->assertSame(0, $role->permissions()->count(), 'precondition: empty role');

        // Assigning via the picker must HEAL the empty role with its baseline map.
        app(TenantRoleProvisioner::class)
            ->setRoleInCompany($user, $company, TenantRoleProvisioner::ROLE_COMPANY_ADMIN);

        $role->unsetRelation('permissions');
        $names = $role->permissions()->pluck('name');
        $this->assertTrue($names->contains('ViewAny:Invoice'), 'company_admin gets the tenant permissions');
        $this->assertFalse($names->contains('ViewAny:User'), 'forbidden resource (User) stays excluded');
    }

    public function test_picker_does_not_clobber_a_customized_role(): void
    {
        $this->seedPermissions();
        $company = $this->makeCompany('custom');
        $user = $this->makeUser();
        $user->companies()->attach($company->id);

        $p = app(TenantRoleProvisioner::class);
        $p->ensureManagedRolesExist($company);

        // Hand-trim the operator role to a single permission (a per-tenant tweak).
        $operator = \App\Models\Role::query()->withoutGlobalScopes()
            ->where('name', TenantRoleProvisioner::ROLE_OPERATOR)
            ->where('company_id', $company->getKey())->first();
        $operator->syncPermissions([Permission::findByName('ViewAny:Invoice', $this->guard)]);
        $this->assertSame(1, $operator->permissions()->count());

        // Assigning it again must NOT re-sync the full baseline (no clobber).
        $p->setRoleInCompany($user, $company, TenantRoleProvisioner::ROLE_OPERATOR);

        $operator->unsetRelation('permissions');
        $this->assertSame(1, $operator->permissions()->count(), 'a non-empty role is left untouched');
    }

    public function test_set_role_rejects_unknown(): void
    {
        $company = $this->makeCompany('bad');
        $user = $this->makeUser();
        $user->companies()->attach($company->id);

        $this->expectException(\InvalidArgumentException::class);
        app(TenantRoleProvisioner::class)->setRoleInCompany($user, $company, 'wizard');
    }
}
