<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\User;
use App\Services\TenantRoleProvisioner;
use BezhanSalleh\FilamentShield\Support\Utils as ShieldUtils;
use Illuminate\Foundation\Testing\RefreshDatabase;
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

    public function test_set_role_rejects_unknown(): void
    {
        $company = $this->makeCompany('bad');
        $user = $this->makeUser();
        $user->companies()->attach($company->id);

        $this->expectException(\InvalidArgumentException::class);
        app(TenantRoleProvisioner::class)->setRoleInCompany($user, $company, 'wizard');
    }
}
