<?php

namespace Tests\Feature\Console;

use App\Models\Company;
use App\Models\User;
use BezhanSalleh\FilamentShield\Support\Utils as ShieldUtils;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * `ekdosi:create-admin` — create/reset a system super_admin without the full
 * installer (the SET-1 companion so a real install never needs `db:seed`).
 */
class CreateAdminTest extends TestCase
{
    use RefreshDatabase;

    private function company(string $slug = 'acme'): Company
    {
        return Company::create([
            'name' => 'ACME', 'slug' => $slug, 'country_code' => 'GR',
            'einvoice_provider' => 'gr-mydata', 'mydata_mode' => 'off',
        ]);
    }

    private function assertHoldsSuperAdmin(User $user, Company $company): void
    {
        app(PermissionRegistrar::class)->setPermissionsTeamId($company->id);
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $this->assertTrue($user->fresh()->hasRole(ShieldUtils::getSuperAdminName()));
    }

    #[Test]
    public function it_creates_a_system_super_admin_across_companies(): void
    {
        $a = $this->company('acme');
        $b = $this->company('beta');

        $this->artisan('ekdosi:create-admin', [
            '--email' => 'new@ekdosi.local', '--password' => 'S3cretpass!', '--name' => 'Νέος', '--no-interaction' => true,
        ])->assertSuccessful();

        $user = User::query()->where('email', 'new@ekdosi.local')->firstOrFail();
        $this->assertTrue($user->companies->contains($a));
        $this->assertTrue($user->companies->contains($b));
        $this->assertHoldsSuperAdmin($user, $a);
        $this->assertHoldsSuperAdmin($user, $b);
    }

    #[Test]
    public function it_refuses_when_no_company_exists(): void
    {
        $this->artisan('ekdosi:create-admin', [
            '--email' => 'x@y.gr', '--password' => 'p', '--no-interaction' => true,
        ])->assertFailed();

        $this->assertSame(0, User::query()->where('email', 'x@y.gr')->count());
    }

    #[Test]
    public function it_requires_a_password_to_create(): void
    {
        $this->company();

        $this->artisan('ekdosi:create-admin', [
            '--email' => 'p@p.gr', '--no-interaction' => true,
        ])->assertExitCode(2); // Command::INVALID

        $this->assertSame(0, User::query()->where('email', 'p@p.gr')->count());
    }

    #[Test]
    public function existing_user_keeps_password_without_reset_but_resets_with_the_flag(): void
    {
        $this->company();
        $user = User::query()->create([
            'name' => 'Old', 'email' => 'e@e.gr', 'password' => Hash::make('oldpass'), 'email_verified_at' => now(),
        ]);

        // Without --reset: promoted to super_admin, password UNCHANGED.
        $this->artisan('ekdosi:create-admin', ['--email' => 'e@e.gr', '--no-interaction' => true])
            ->assertSuccessful();
        $this->assertTrue(Hash::check('oldpass', $user->fresh()->password));

        // With --reset: password changes.
        $this->artisan('ekdosi:create-admin', ['--email' => 'e@e.gr', '--password' => 'brandnew!', '--reset' => true, '--no-interaction' => true])
            ->assertSuccessful();
        $this->assertTrue(Hash::check('brandnew!', $user->fresh()->password));
    }
}
