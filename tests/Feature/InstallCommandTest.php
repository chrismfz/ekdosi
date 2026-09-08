<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\InvoiceType;
use App\Models\User;
use App\Models\VatCategory;
use App\Services\Portability\BundleArchive;
use App\Services\Portability\CompanyExporter;
use App\Services\TenantRoleProvisioner;
use BezhanSalleh\FilamentShield\Support\Utils as ShieldUtils;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
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

    /** Build a source company (setup + one operator) and return its bundle .zip path. */
    private function makeBundle(string $opEmail = 'op@myip.gr'): string
    {
        $source = Company::create([
            'name' => 'MyIP OE', 'slug' => 'myip', 'country_code' => 'GR',
            'einvoice_provider' => 'gr-mydata', 'afm' => '800561849',
            'mydata_subscription_key_production' => 'PRODKEY', 'gsis_password' => 'gsis-pw',
        ]);
        VatCategory::create(['company_id' => $source->id, 'description' => 'ΦΠΑ 24%', 'rate' => 24, 'is_default' => true]);
        InvoiceType::create(['company_id' => $source->id, 'code' => 'TPY', 'name' => 'ΤΠΥ', 'invcount' => 5]);

        $op = User::create(['name' => 'Op', 'email' => $opEmail, 'password' => bcrypt('src')]);
        $op->companies()->syncWithoutDetaching([$source->id]);
        app(TenantRoleProvisioner::class)->setRoleInCompany($op, $source, TenantRoleProvisioner::ROLE_OPERATOR);

        $bundle = app(CompanyExporter::class)->build($source, 'passphrase', 'p@ss');
        $path = tempnam(sys_get_temp_dir(), 'ekb').'.zip';
        app(BundleArchive::class)->write($path, $bundle);

        // Simulate a FRESH box: drop the source company + all users.
        Company::query()->forceDelete();
        User::query()->forceDelete();

        return $path;
    }

    #[Test]
    public function it_installs_the_first_company_from_a_bundle(): void
    {
        $this->withoutMockingConsoleOutput();
        $path = $this->makeBundle();

        try {
            $exit = $this->artisan('ekdosi:install', [
                '--bundle' => $path,
                '--bundle-passphrase' => 'p@ss',
                '--email' => 'boss@myip.gr',
                '--password' => 'secret123',
                '--no-interaction' => true,
            ]);
            $this->assertSame(0, $exit);
        } finally {
            @unlink($path);
        }

        // Company restored from the bundle (identity + settings + sealed secrets).
        $company = Company::query()->where('slug', 'myip')->firstOrFail();
        $this->assertSame('MyIP OE', $company->name);
        $this->assertSame('800561849', $company->afm);
        $this->assertSame('PRODKEY', $company->mydata_subscription_key_production);
        $this->assertSame('gsis-pw', $company->gsis_password);

        // Setup restored and NOT double-seeded: exactly the bundle's one VAT + one
        // type (lookup seeding would have added the standard set → count > 1).
        $this->assertSame(1, VatCategory::where('company_id', $company->id)->count());
        $this->assertSame(1, InvoiceType::where('company_id', $company->id)->count());
        $this->assertSame(5, (int) InvoiceType::where('company_id', $company->id)->where('code', 'TPY')->value('invcount'));

        // Install admin created + super_admin.
        $provisioner = app(TenantRoleProvisioner::class);
        $admin = User::query()->where('email', 'boss@myip.gr')->firstOrFail();
        $this->assertTrue($admin->companies->contains($company));
        $this->assertTrue($provisioner->isSystemSuperAdmin($admin));

        // Bundle operator re-created + linked with their role.
        $op = User::query()->where('email', 'op@myip.gr')->firstOrFail();
        $this->assertTrue($op->companies->contains($company));
        $this->assertSame(TenantRoleProvisioner::ROLE_OPERATOR, $provisioner->roleInCompany($op, $company));
    }

    #[Test]
    public function bundle_install_admin_keeps_the_specified_password_even_when_the_bundle_lists_that_email(): void
    {
        $this->withoutMockingConsoleOutput();
        // The bundle lists the install admin's own email as an operator — the
        // importer must not mint them with a random password before the admin
        // creation runs (ordering guard).
        $path = $this->makeBundle(opEmail: 'boss@myip.gr');

        try {
            $exit = $this->artisan('ekdosi:install', [
                '--bundle' => $path,
                '--bundle-passphrase' => 'p@ss',
                '--email' => 'boss@myip.gr',
                '--password' => 'secret123',
                '--no-interaction' => true,
            ]);
            $this->assertSame(0, $exit);
        } finally {
            @unlink($path);
        }

        $company = Company::query()->where('slug', 'myip')->firstOrFail();
        $admin = User::query()->where('email', 'boss@myip.gr')->firstOrFail();
        // The admin logs in with the SPECIFIED password (not a random one).
        $this->assertTrue(Hash::check('secret123', $admin->password));

        $provisioner = app(TenantRoleProvisioner::class);
        $this->assertTrue($provisioner->isSystemSuperAdmin($admin));
        // …and ONLY super_admin — the bundle's operator role for that email was
        // stripped, so the install admin isn't left as operator + super_admin.
        $this->assertFalse($provisioner->userHoldsRole($admin, $company, TenantRoleProvisioner::ROLE_OPERATOR));
    }

    #[Test]
    public function bundle_install_cleans_up_the_freshly_created_admin_when_the_import_fails(): void
    {
        $this->withoutMockingConsoleOutput();
        $path = $this->makeBundle();
        // A company already occupies the bundle's slug → importer --new throws.
        Company::create([
            'name' => 'Occupied', 'slug' => 'myip', 'country_code' => 'GR', 'einvoice_provider' => 'gr-mydata',
        ]);

        try {
            $exit = $this->artisan('ekdosi:install', [
                '--bundle' => $path, '--bundle-passphrase' => 'p@ss',
                '--email' => 'boss@myip.gr', '--password' => 'secret123', '--no-interaction' => true,
            ]);
            $this->assertSame(1, $exit);
        } finally {
            @unlink($path);
        }

        // The just-created admin was rolled back — it must not strand the retry
        // behind the populated-install guard.
        $this->assertSame(0, User::query()->where('email', 'boss@myip.gr')->count());
    }

    #[Test]
    public function bundle_install_fails_cleanly_without_a_passphrase_in_non_interactive_mode(): void
    {
        $this->withoutMockingConsoleOutput();
        $path = $this->makeBundle();

        try {
            $exit = $this->artisan('ekdosi:install', [
                '--bundle' => $path,
                '--email' => 'boss@myip.gr',
                '--password' => 'secret123',
                '--no-interaction' => true,
            ]);
            $this->assertSame(1, $exit);
        } finally {
            @unlink($path);
        }

        // Nothing provisioned — the passphrase gate fires before the import.
        $this->assertSame(0, Company::query()->where('slug', 'myip')->count());
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
    public function it_rejects_a_password_under_eight_characters(): void
    {
        // SEC-2: a --password flag can't seed a weak super_admin.
        $this->withoutMockingConsoleOutput();

        $exit = $this->artisan('ekdosi:install', [
            '--email' => 'boss@acme.gr', '--password' => 'short',
            '--company' => 'ACME', '--slug' => 'acme', '--no-interaction' => true,
        ]);
        $this->assertSame(1, $exit);

        // Nothing was created — the guard fires before the transaction.
        $this->assertSame(0, Company::query()->where('slug', 'acme')->count());
        $this->assertSame(0, User::query()->where('email', 'boss@acme.gr')->count());
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
