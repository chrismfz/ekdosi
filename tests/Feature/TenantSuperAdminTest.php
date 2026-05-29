<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Role;
use App\Models\User;
use App\Services\TenantRoleProvisioner;
use BezhanSalleh\FilamentShield\Support\Utils as ShieldUtils;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Regression for the "new tenant strips the admin's menu" bug.
 *
 * Under Shield teams mode the super_admin role exists per company. A company
 * created outside the seeder (e.g. the Companies UI — the nexon case) had no
 * super_admin role for its team, so an admin switching into it lost the
 * Gate::before bypass. The CompanyObserver + TenantRoleProvisioner +
 * shield:sync-super-admin command fix that.
 */
class TenantSuperAdminTest extends TestCase
{
    use RefreshDatabase;

    private function makeCompany(string $slug): Company
    {
        return Company::create([
            'name' => $slug,
            'slug' => $slug.'-'.uniqid(),
            'country_code' => 'GR',
            'einvoice_provider' => 'gr-mydata',
            'mydata_mode' => 'off',
        ]);
    }

    private function superAdminName(): string
    {
        return ShieldUtils::getSuperAdminName();
    }

    /** Has the user got super_admin in this company's team? */
    private function hasSuperAdminIn(User $user, Company $company): bool
    {
        $registrar = app(PermissionRegistrar::class);
        $registrar->setPermissionsTeamId($company->getKey());
        $registrar->forgetCachedPermissions();
        $user->unsetRelation('roles');

        return $user->hasRole($this->superAdminName());
    }

    public function test_creating_a_company_auto_creates_its_super_admin_role(): void
    {
        $company = $this->makeCompany('auto');

        app(PermissionRegistrar::class)->setPermissionsTeamId($company->getKey());

        $exists = Role::query()
            ->where('name', $this->superAdminName())
            ->where('company_id', $company->getKey())
            ->exists();

        $this->assertTrue($exists, 'CompanyObserver should create the per-team super_admin role on create.');
    }

    public function test_provisioner_is_idempotent(): void
    {
        $company = $this->makeCompany('idem');
        $p = app(TenantRoleProvisioner::class);

        $p->ensureSuperAdminRole($company);
        $p->ensureSuperAdminRole($company);

        $count = Role::query()
            ->where('name', $this->superAdminName())
            ->where('company_id', $company->getKey())
            ->count();

        $this->assertSame(1, $count);
    }

    public function test_assign_grants_bypass_in_that_tenant_only(): void
    {
        $a = $this->makeCompany('a');
        $b = $this->makeCompany('b');

        $user = User::create([
            'name' => 'Op', 'email' => 'op-'.uniqid().'@test.local', 'password' => bcrypt('x'),
        ]);
        $user->companies()->attach([$a->id, $b->id]);

        app(TenantRoleProvisioner::class)->assignSuperAdmin($user, $a);

        $this->assertTrue($this->hasSuperAdminIn($user, $a));
        $this->assertFalse($this->hasSuperAdminIn($user, $b), 'super_admin must stay scoped to tenant A.');
        $this->assertTrue(app(TenantRoleProvisioner::class)->isSuperAdminAnywhere($user));
    }

    public function test_sync_command_backfills_super_admin_across_a_users_tenants(): void
    {
        // Simulate the real bug: user is super_admin in the first company only,
        // then a SECOND company is created + attached (the nexon scenario).
        $first = $this->makeCompany('first');
        $second = $this->makeCompany('second');

        $user = User::create([
            'name' => 'Admin', 'email' => 'admin-'.uniqid().'@test.local', 'password' => bcrypt('x'),
        ]);
        $user->companies()->attach([$first->id, $second->id]);

        // Admin is super_admin in `first` only.
        app(TenantRoleProvisioner::class)->assignSuperAdmin($user, $first);
        $this->assertFalse($this->hasSuperAdminIn($user, $second), 'precondition: missing in second');

        // Backfill: re-sync anyone super_admin somewhere across their tenants.
        $this->artisan('shield:sync-super-admin')->assertExitCode(0);

        $this->assertTrue($this->hasSuperAdminIn($user, $second), 'backfill should grant super_admin in the second tenant.');
        $this->assertTrue($this->hasSuperAdminIn($user, $first), 'and keep it in the first.');
    }

    public function test_sync_command_user_flag_targets_one_user(): void
    {
        $c = $this->makeCompany('targeted');
        $user = User::create([
            'name' => 'U', 'email' => 'u-'.uniqid().'@test.local', 'password' => bcrypt('x'),
        ]);
        $user->companies()->attach($c->id);

        $this->artisan('shield:sync-super-admin', ['--user' => $user->email])->assertExitCode(0);

        $this->assertTrue($this->hasSuperAdminIn($user, $c));
    }
}
