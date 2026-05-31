<?php

namespace Database\Seeders;

use App\Models\Company;
use App\Models\Role;
use App\Models\User;
use BezhanSalleh\FilamentShield\Support\Utils as ShieldUtils;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\PermissionRegistrar;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed three tenants (2 Greek/myDATA + 1 Estonian/PEPPOL), an admin
     * user attached to all three, AND a super_admin role per tenant.
     *
     * Order matters: tenants must exist BEFORE shield:generate, because
     * with config/filament-shield.php's tenant_model = Company, Shield
     * loops over Company::all() and creates a super_admin role row per
     * tenant automatically — saves us from manual Role::firstOrCreate
     * calls and avoids leaving an orphan company_id=NULL role row
     * (which is what would happen if shield:generate runs against an
     * empty companies table).
     */
    public function run(): void
    {
        // (1) Tenants first
        $myip = Company::create([
            'name' => 'myip',
            'slug' => 'myip',
            'country_code' => 'GR',
            'einvoice_provider' => 'gr-mydata',
            'afm' => '999999999',
            'tax_office' => 'Athens',
            'mydata_mode' => 'off',
        ]);

        $nixpal = Company::create([
            'name' => 'nixpal',
            'slug' => 'nixpal',
            'country_code' => 'GR',
            'einvoice_provider' => 'gr-mydata',
            'afm' => '888888888',
            'tax_office' => 'Athens',
            'mydata_mode' => 'off',
        ]);

        $estonian = Company::create([
            'name' => 'Sample EE OÜ',
            'slug' => 'sample-ee',
            'country_code' => 'EE',
            'einvoice_provider' => 'ee-peppol',
            'mydata_mode' => 'off',
        ]);

        // (2) Permissions + auto-created per-tenant super_admin roles
        //     with the full permission set already attached.
        Artisan::call('shield:generate', [
            '--all' => true,
            '--panel' => 'admin',
            '--ignore-existing-policies' => true,
            '--no-interaction' => true,
        ]);

        // (2b) Standard non-super roles (company_admin, operator) per tenant,
        //      AFTER shield:generate so their permission maps attach the
        //      now-existing permissions. The CompanyObserver created the role
        //      rows on (1) but permissions didn't exist yet — re-sync here.
        $provisioner = app(\App\Services\TenantRoleProvisioner::class);
        foreach ([$myip, $nixpal, $estonian] as $company) {
            $provisioner->ensureStandardRoles($company);
        }

        // (3) Admin user attached to every tenant
        $admin = User::create([
            'name' => 'Admin',
            'email' => 'admin@ekdosi.local',
            'password' => Hash::make('password'),
            'email_verified_at' => now(),
        ]);

        $admin->companies()->attach([$myip->id, $nixpal->id, $estonian->id]);

        // (4) Assign each tenant's super_admin role to the admin user.
        $superAdminName = ShieldUtils::getSuperAdminName();
        $registrar = app(PermissionRegistrar::class);

        foreach ([$myip, $nixpal, $estonian] as $company) {
            $registrar->setPermissionsTeamId($company->id);
            $registrar->forgetCachedPermissions();

            $role = Role::query()
                ->where('name', $superAdminName)
                ->where('guard_name', 'web')
                ->where('company_id', $company->id)
                ->firstOrFail();

            $admin->assignRole($role);
        }
    }
}
