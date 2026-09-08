<?php

namespace Tests\Feature\Updates;

use App\Models\Company;
use App\Models\UpdateRun;
use App\Models\User;
use App\Policies\UpdateRunPolicy;
use App\Services\TenantRoleProvisioner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * The `UpdateRun` authorization boundary at the GATE (not just the Filament
 * resource's canViewAny overrides).
 *
 * `UpdateRun` is the whole-app deploy history: global, cross-tenant, immutable.
 * Before this, it had NO policy and was NOT in ADMIN_FORBIDDEN_RESOURCES — so
 * every company_admin held `*:UpdateRun`, and a stock shield-generated policy
 * (which is what kept getting written into the working tree) would have APPROVED
 * them on another tenant's deploy record.
 *
 * Two layers, both asserted here: the permissions are no longer granted, and the
 * hand-written policy denies anyway.
 */
class UpdateRunAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    private Company $tenant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Company::create([
            'name' => 'Host', 'slug' => 'h-'.uniqid(), 'country_code' => 'GR',
            'einvoice_provider' => 'gr-mydata', 'mydata_mode' => 'off',
        ]);

        foreach (['ViewAny', 'View', 'Create', 'Update', 'Delete'] as $action) {
            Permission::firstOrCreate(['name' => $action.':UpdateRun', 'guard_name' => 'web']);
            Permission::firstOrCreate(['name' => $action.':Invoice', 'guard_name' => 'web']);
        }

        app(PermissionRegistrar::class)->setPermissionsTeamId($this->tenant->id);
    }

    private function user(string $role): User
    {
        $user = User::create(['name' => $role, 'email' => $role.'-'.uniqid().'@t.local', 'password' => bcrypt('x')]);
        $user->companies()->attach($this->tenant->id);

        $provisioner = app(TenantRoleProvisioner::class);
        $provisioner->ensureStandardRoles($this->tenant);
        $provisioner->assignStandardRole($user, $this->tenant, $role);

        app(PermissionRegistrar::class)->setPermissionsTeamId($this->tenant->id);
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        return $user->fresh();
    }

    #[Test]
    public function a_company_admin_is_no_longer_granted_the_update_run_permissions(): void
    {
        $admin = $this->user(TenantRoleProvisioner::ROLE_COMPANY_ADMIN);

        // Sanity: they DO get the ordinary tenant resources…
        $this->assertTrue($admin->can('ViewAny:Invoice'));
        // …but not the cross-tenant deploy history.
        foreach (['ViewAny', 'View', 'Create', 'Update', 'Delete'] as $action) {
            $this->assertFalse($admin->can($action.':UpdateRun'), $action.':UpdateRun leaked to company_admin');
        }
    }

    #[Test]
    public function the_policy_denies_a_company_admin_even_if_they_somehow_hold_the_permission(): void
    {
        $admin = $this->user(TenantRoleProvisioner::ROLE_COMPANY_ADMIN);

        // Belt AND braces: hand them every UpdateRun permission directly, the way
        // a stock shield-generated policy would have honoured.
        $admin->givePermissionTo(Permission::query()->where('name', 'like', '%:UpdateRun')->pluck('name')->all());
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $run = UpdateRun::create(['status' => UpdateRun::STATUS_SUCCEEDED, 'kind' => UpdateRun::KIND_UPDATE]);
        $gate = Gate::forUser($admin->fresh());

        $this->assertFalse($gate->allows('viewAny', UpdateRun::class));
        $this->assertFalse($gate->allows('view', $run));
        $this->assertFalse($gate->allows('create', UpdateRun::class));
        $this->assertFalse($gate->allows('update', $run));
        $this->assertFalse($gate->allows('delete', $run));
        $this->assertFalse($gate->allows('forceDelete', $run));
        $this->assertFalse($gate->allows('restore', $run));
        $this->assertFalse($gate->allows('replicate', $run));
    }

    #[Test]
    public function an_operator_and_a_plain_user_are_denied_too(): void
    {
        $run = UpdateRun::create(['status' => UpdateRun::STATUS_SUCCEEDED, 'kind' => UpdateRun::KIND_UPDATE]);

        $operator = $this->user(TenantRoleProvisioner::ROLE_OPERATOR);
        $this->assertFalse(Gate::forUser($operator)->allows('viewAny', UpdateRun::class));
        $this->assertFalse(Gate::forUser($operator)->allows('view', $run));

        $plain = User::create(['name' => 'P', 'email' => 'p-'.uniqid().'@t.local', 'password' => bcrypt('x')]);
        $plain->companies()->attach($this->tenant->id);
        $this->assertFalse(Gate::forUser($plain)->allows('viewAny', UpdateRun::class));
    }

    /**
     * Gate::before gives a system super admin a global bypass, so the two Gate
     * tests above never reach the policy for them. Call it directly, so the
     * policy's OWN answer is asserted — and assert it is the class actually
     * bound to the model (a regenerated shield stub would replace it).
     */
    #[Test]
    public function the_policy_itself_says_super_admin_only_and_is_the_bound_policy(): void
    {
        $this->assertInstanceOf(UpdateRunPolicy::class, Gate::getPolicyFor(UpdateRun::class));

        $policy = new UpdateRunPolicy;
        $run = UpdateRun::create(['status' => UpdateRun::STATUS_SUCCEEDED, 'kind' => UpdateRun::KIND_UPDATE]);

        $admin = $this->user(TenantRoleProvisioner::ROLE_COMPANY_ADMIN);
        $this->assertFalse($policy->viewAny($admin));
        $this->assertFalse($policy->view($admin, $run));

        $super = User::create(['name' => 'S2', 'email' => 's2-'.uniqid().'@t.local', 'password' => bcrypt('x')]);
        $super->companies()->attach($this->tenant->id);
        app(TenantRoleProvisioner::class)->assignSuperAdmin($super, $this->tenant);

        $this->assertTrue($policy->viewAny($super->fresh()));
        $this->assertTrue($policy->view($super->fresh(), $run));
        $this->assertFalse($policy->update($super->fresh(), $run), 'immutable for everyone the policy sees');
        $this->assertFalse($policy->delete($super->fresh(), $run));
    }

    #[Test]
    public function a_super_admin_reads_the_history(): void
    {
        $super = User::create(['name' => 'S', 'email' => 's-'.uniqid().'@t.local', 'password' => bcrypt('x')]);
        $super->companies()->attach($this->tenant->id);
        app(TenantRoleProvisioner::class)->assignSuperAdmin($super, $this->tenant);

        $run = UpdateRun::create(['status' => UpdateRun::STATUS_SUCCEEDED, 'kind' => UpdateRun::KIND_UPDATE]);

        $this->assertTrue(Gate::forUser($super->fresh())->allows('viewAny', UpdateRun::class));
        $this->assertTrue(Gate::forUser($super->fresh())->allows('view', $run));
    }
}
