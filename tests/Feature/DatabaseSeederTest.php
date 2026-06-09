<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\User;
use BezhanSalleh\FilamentShield\Support\Utils as ShieldUtils;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * The default seed is the fresh-install path: ONE admin + the DEMO company,
 * wired through shield:generate so the admin can actually log in and operate.
 */
class DatabaseSeederTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function it_seeds_only_the_demo_company_with_a_super_admin(): void
    {
        // shield:generate (run inside the seeder) prints/prompts; let it use real I/O.
        $this->withoutMockingConsoleOutput();
        $this->seed(DatabaseSeeder::class);

        // Exactly one tenant — the DEMO company (no myip/nixpal/sample-ee).
        $this->assertSame(1, Company::query()->count());
        $demo = Company::query()->where('slug', 'demo')->firstOrFail();

        $admin = User::query()->where('email', 'admin@ekdosi.local')->firstOrFail();
        $this->assertTrue($admin->companies->contains($demo));

        // The admin holds the DEMO super_admin role (team-scoped).
        app(PermissionRegistrar::class)->setPermissionsTeamId($demo->id);
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $this->assertTrue($admin->fresh()->hasRole(ShieldUtils::getSuperAdminName()));
    }

    #[Test]
    public function re_seeding_is_idempotent(): void
    {
        $this->withoutMockingConsoleOutput();
        $this->seed(DatabaseSeeder::class);
        $this->seed(DatabaseSeeder::class);

        $this->assertSame(1, Company::query()->where('slug', 'demo')->count());
        $this->assertSame(1, User::query()->where('email', 'admin@ekdosi.local')->count());
    }
}
