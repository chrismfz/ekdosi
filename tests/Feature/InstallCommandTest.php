<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\InvoiceType;
use App\Models\User;
use App\Models\VatCategory;
use App\Services\TenantRoleProvisioner;
use BezhanSalleh\FilamentShield\Support\Utils as ShieldUtils;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * `ekdosi:install` — turnkey first-run: creates the first super_admin + company,
 * wires Shield, seeds the standard Greek lookups, and refuses to clobber a
 * populated install without --force.
 *
 * NB: the command runs shield:generate, which interacts with the console even
 * via Artisan::call — so these use the real output (`withoutMockingConsoleOutput`)
 * and assert on the integer exit code + DB state, not the PendingCommand fluent API.
 */
class InstallCommandTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function it_creates_the_first_admin_company_and_lookups(): void
    {
        $this->withoutMockingConsoleOutput();

        $exit = $this->artisan('ekdosi:install', [
            '--email' => 'boss@acme.gr',
            '--password' => 'secret123',
            '--company' => 'ACME ΑΕ',
            '--slug' => 'acme',
            '--afm' => '800561849',
            '--no-interaction' => true,
        ]);
        $this->assertSame(0, $exit);

        $company = Company::query()->where('slug', 'acme')->firstOrFail();
        $admin = User::query()->where('email', 'boss@acme.gr')->firstOrFail();
        $this->assertTrue($admin->companies->contains($company));

        // super_admin in the new tenant.
        app(PermissionRegistrar::class)->setPermissionsTeamId($company->id);
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $this->assertTrue($admin->fresh()->hasRole(ShieldUtils::getSuperAdminName()));

        // Standard roles + lookups present.
        $this->assertContains(TenantRoleProvisioner::ROLE_OPERATOR, app(TenantRoleProvisioner::class)->managedRoleNames());
        $this->assertGreaterThan(0, VatCategory::where('company_id', $company->id)->count());
        $this->assertGreaterThan(0, InvoiceType::where('company_id', $company->id)->count());
    }

    #[Test]
    public function it_refuses_on_a_populated_install_without_force(): void
    {
        $this->withoutMockingConsoleOutput();
        User::create(['name' => 'Existing', 'email' => 'x@x.gr', 'password' => bcrypt('x')]);

        $exit = $this->artisan('ekdosi:install', [
            '--email' => 'boss@acme.gr', '--password' => 'secret123',
            '--company' => 'ACME', '--slug' => 'acme', '--no-interaction' => true,
        ]);
        $this->assertSame(1, $exit);

        $this->assertSame(0, Company::query()->where('slug', 'acme')->count());
    }

    #[Test]
    public function non_interactive_without_password_fails_cleanly(): void
    {
        $this->withoutMockingConsoleOutput();

        $exit = $this->artisan('ekdosi:install', [
            '--email' => 'boss@acme.gr', '--company' => 'ACME', '--slug' => 'acme',
            '--no-interaction' => true,
        ]);
        $this->assertSame(1, $exit);
        $this->assertSame(0, Company::query()->where('slug', 'acme')->count());
    }

    #[Test]
    public function no_lookups_flag_skips_seeding(): void
    {
        $this->withoutMockingConsoleOutput();

        $exit = $this->artisan('ekdosi:install', [
            '--email' => 'boss@acme.gr', '--password' => 'secret123',
            '--company' => 'ACME', '--slug' => 'acme', '--no-lookups' => true, '--no-interaction' => true,
        ]);
        $this->assertSame(0, $exit);

        $company = Company::query()->where('slug', 'acme')->firstOrFail();
        $this->assertSame(0, VatCategory::where('company_id', $company->id)->count());
        $this->assertSame(0, InvoiceType::where('company_id', $company->id)->count());
    }

    #[Test]
    public function force_lets_a_second_company_be_added(): void
    {
        $this->withoutMockingConsoleOutput();
        User::create(['name' => 'Existing', 'email' => 'x@x.gr', 'password' => bcrypt('x')]);

        $exit = $this->artisan('ekdosi:install', [
            '--email' => 'boss2@acme.gr', '--password' => 'secret123',
            '--company' => 'Second ΑΕ', '--slug' => 'second', '--force' => true, '--no-interaction' => true,
        ]);
        $this->assertSame(0, $exit);

        $this->assertSame(1, Company::query()->where('slug', 'second')->count());
    }
}
