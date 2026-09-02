<?php

namespace Tests\Feature\Roles;

use App\Models\Company;
use App\Services\TenantRoleProvisioner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * `roles:reprovision` — the step that was missing after every new resource:
 * `shield:generate` creates the permission rows and `shield:sync-super-admin`
 * gives them to the super_admin, but company_admin/operator only picked them
 * up when someone re-assigned the role by hand (which is why Leads stayed
 * invisible to operators). Additive by default; --prune aligns fully.
 */
class RolesReprovisionTest extends TestCase
{
    use RefreshDatabase;

    private function tenant(string $slug): Company
    {
        return Company::create([
            'name' => 'Co '.$slug, 'slug' => $slug,
            'country_code' => 'GR', 'einvoice_provider' => 'gr-mydata', 'mydata_mode' => 'off',
        ]);
    }

    private function permission(string $name): Permission
    {
        return Permission::firstOrCreate(['name' => $name, 'guard_name' => 'web']);
    }

    private function operatorRole(Company $company)
    {
        return app(TenantRoleProvisioner::class)->findManagedRole(TenantRoleProvisioner::ROLE_OPERATOR, $company);
    }

    public function test_a_new_resources_permission_reaches_every_tenants_operator(): void
    {
        $a = $this->tenant('alpha');
        $b = $this->tenant('beta');

        // A resource that shipped AFTER the roles were provisioned (exactly the
        // Leads case): shield:generate created the rows, nobody granted them.
        $this->permission('ViewAny:Lead');
        $this->permission('View:LeadsBoard');

        $this->assertFalse($this->operatorRole($a)->hasPermissionTo('ViewAny:Lead'));

        $this->artisan('roles:reprovision --force')
            ->expectsOutputToContain('Δόθηκαν')
            ->assertExitCode(0);

        foreach ([$a, $b] as $company) {
            $this->assertTrue($this->operatorRole($company)->hasPermissionTo('ViewAny:Lead'));
            $this->assertTrue($this->operatorRole($company)->hasPermissionTo('View:LeadsBoard'));
        }
    }

    public function test_dry_run_changes_nothing_and_a_second_run_is_a_no_op(): void
    {
        $a = $this->tenant('alpha');
        $this->permission('ViewAny:Lead');

        $this->artisan('roles:reprovision --dry-run')
            ->expectsOutputToContain('Dry-run')
            ->assertExitCode(0);
        $this->assertFalse($this->operatorRole($a)->hasPermissionTo('ViewAny:Lead'));

        $this->artisan('roles:reprovision --force')->assertExitCode(0);
        $this->artisan('roles:reprovision --force')
            ->expectsOutputToContain('ήδη συγχρονισμένοι')
            ->assertExitCode(0);
    }

    public function test_a_manual_grant_survives_by_default_and_only_prune_removes_it(): void
    {
        $a = $this->tenant('alpha');
        $extra = $this->permission('Delete:Invoice');   // deliberately NOT in the operator map
        $this->operatorRole($a)->givePermissionTo($extra);

        // Additive default: never revokes what a tenant customised.
        $this->artisan('roles:reprovision --force')->assertExitCode(0);
        $this->assertTrue($this->operatorRole($a)->hasPermissionTo('Delete:Invoice'));

        // …and --prune says exactly what it will remove, then removes it.
        $this->artisan('roles:reprovision --prune --force')
            ->expectsOutputToContain('Delete:Invoice')
            ->assertExitCode(0);
        $this->assertFalse($this->operatorRole($a)->fresh()->hasPermissionTo('Delete:Invoice'));
    }

    public function test_one_tenant_only_and_an_unknown_slug_is_an_input_error(): void
    {
        $a = $this->tenant('alpha');
        $b = $this->tenant('beta');
        $this->permission('ViewAny:Lead');

        $this->artisan('roles:reprovision --tenant=alpha --force')->assertExitCode(0);

        $this->assertTrue($this->operatorRole($a)->hasPermissionTo('ViewAny:Lead'));
        $this->assertFalse($this->operatorRole($b)->hasPermissionTo('ViewAny:Lead'), 'the other tenant was untouched');

        $this->artisan('roles:reprovision --tenant=nope')->assertExitCode(2);
    }

    public function test_company_admin_gets_everything_except_the_forbidden_resources(): void
    {
        $a = $this->tenant('alpha');
        $this->permission('ViewAny:Lead');
        $this->permission('ViewAny:Company');   // panel-global — never for a tenant admin

        $this->artisan('roles:reprovision --force')->assertExitCode(0);

        $admin = app(TenantRoleProvisioner::class)->findManagedRole(TenantRoleProvisioner::ROLE_COMPANY_ADMIN, $a);
        $this->assertTrue($admin->hasPermissionTo('ViewAny:Lead'));
        $this->assertFalse($admin->hasPermissionTo('ViewAny:Company'));
    }
}
