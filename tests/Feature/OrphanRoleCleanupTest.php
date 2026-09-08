<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Services\TenantRoleProvisioner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Tenant roles (spatie teams mode, `roles.company_id`) have no FK cascade to
 * companies. The CompanyObserver cleans them on delete, and ekdosi:prune-orphan-
 * roles mops up leftovers — so a reused company id can't hit
 * «Duplicate entry '<id>-super_admin-web'» on provisioning.
 */
class OrphanRoleCleanupTest extends TestCase
{
    use RefreshDatabase;

    private function company(string $slug): Company
    {
        return Company::create([
            'name' => $slug, 'slug' => $slug, 'country_code' => 'GR',
            'einvoice_provider' => 'gr-mydata', 'mydata_mode' => 'off',
        ]);
    }

    public function test_deleting_company_removes_its_roles(): void
    {
        $company = $this->company('rolico');
        $id = $company->id;
        $this->assertTrue(DB::table('roles')->where('company_id', $id)->exists()); // provisioned on create

        $company->delete();   // instance delete → fires the `deleted` observer

        $this->assertFalse(DB::table('roles')->where('company_id', $id)->exists());
    }

    public function test_ensure_super_admin_role_is_idempotent(): void
    {
        $company = $this->company('idemco');   // observer already provisioned super_admin
        $provisioner = app(TenantRoleProvisioner::class);

        $first = $provisioner->ensureSuperAdminRole($company);
        $again = $provisioner->ensureSuperAdminRole($company);   // must NOT throw a duplicate

        $this->assertSame($first->id, $again->id);
        $this->assertSame(1, DB::table('roles')
            ->where('company_id', $company->id)->where('name', 'super_admin')->count());
    }

    public function test_prune_command_deletes_only_orphan_roles(): void
    {
        $live = $this->company('livco');
        // Orphan: a role whose company_id matches no company (e.g. a deleted tenant).
        DB::table('roles')->insert([
            'name' => 'super_admin', 'guard_name' => 'web', 'company_id' => 999999,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        // Dry-run lists but doesn't delete.
        $this->artisan('ekdosi:prune-orphan-roles')
            ->expectsOutputToContain('999999')
            ->assertSuccessful();
        $this->assertTrue(DB::table('roles')->where('company_id', 999999)->exists());

        // --execute removes the orphan, keeps the live tenant's roles.
        $this->artisan('ekdosi:prune-orphan-roles', ['--execute' => true])->assertSuccessful();

        $this->assertFalse(DB::table('roles')->where('company_id', 999999)->exists());
        $this->assertTrue(DB::table('roles')->where('company_id', $live->id)->exists());
    }
}
