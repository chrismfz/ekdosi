<?php

namespace Database\Seeders;

use App\Models\Company;
use App\Models\Role;
use App\Models\User;
use App\Services\TenantRoleProvisioner;
use BezhanSalleh\FilamentShield\Support\Utils as ShieldUtils;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\PermissionRegistrar;

/**
 * Default seed = ONE self-contained «DEMO Α.Ε.» tenant (full demo mode) + an
 * admin user, so a fresh clone / a reviewer gets a working company to log into
 * immediately. The old per-developer fixtures (myip / nixpal / sample-ee) are
 * gone — real tenants are created via the install wizard or the ETL, not here.
 *
 * Order matters: the DEMO company must exist BEFORE shield:generate, because
 * with config/filament-shield.php's tenant_model = Company, Shield loops over
 * Company::all() and creates a super_admin role row per tenant — so running it
 * against an empty companies table would leave an orphan company_id=NULL role.
 */
class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    public function run(): void
    {
        // (1) Admin user first, so DemoCompanySeeder::attachAdmin finds it.
        $admin = User::query()->firstOrCreate(
            ['email' => 'admin@ekdosi.local'],
            [
                'name' => 'Admin',
                'password' => Hash::make('password'),
                'email_verified_at' => now(),
            ],
        );

        // (2) The DEMO tenant: roles, lookups, catalogue, customers, invoices,
        //     delivery notes — and it attaches $admin to itself.
        $this->call(DemoCompanySeeder::class);

        $demo = Company::query()->where('slug', 'demo')->first();
        if ($demo === null) {
            // Already seeded earlier (idempotent skip) — nothing else to wire.
            return;
        }

        // (3) Permissions + the auto-created per-tenant super_admin role with the
        //     full permission set (the DEMO company now exists, so no orphan row).
        Artisan::call('shield:generate', [
            '--all' => true,
            '--panel' => 'admin',
            '--ignore-existing-policies' => true,
            '--no-interaction' => true,
        ]);

        // (4) (Re)populate the standard roles AFTER shield:generate so their
        //     permission maps attach the now-existing permissions.
        app(TenantRoleProvisioner::class)->ensureStandardRoles($demo);

        // (5) Ensure the admin holds the DEMO super_admin role.
        $registrar = app(PermissionRegistrar::class);
        $registrar->setPermissionsTeamId($demo->id);
        $registrar->forgetCachedPermissions();

        $role = Role::query()
            ->where('name', ShieldUtils::getSuperAdminName())
            ->where('guard_name', 'web')
            ->where('company_id', $demo->id)
            ->first();

        if ($role !== null) {
            $admin->assignRole($role);
        }
    }
}
