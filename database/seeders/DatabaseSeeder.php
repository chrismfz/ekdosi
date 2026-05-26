<?php

namespace Database\Seeders;

use App\Models\Company;
use App\Models\User;
use BezhanSalleh\FilamentShield\Support\Utils as ShieldUtils;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed three tenants matching the planned production shape (myip + nixpal
     * on myDATA, one Estonian on the PEPPOL stub), an admin user attached to
     * all three, AND a super_admin role per tenant (Spatie teams mode is on,
     * so roles are scoped to companies). Shield's policies treat super_admin
     * as the bypass-everything role.
     */
    public function run(): void
    {
        // (1) Populate Shield's permissions table from the discovered resources
        //     (CompanyResource → 12 permissions × 2 entities = 24 permissions
        //     when this PR runs; grows automatically as we add resources).
        Artisan::call('shield:generate', [
            '--all' => true,
            '--panel' => 'admin',
            '--ignore-existing-policies' => true,
            '--no-interaction' => true,
        ]);

        // (2) Tenants
        $myip = Company::create([
            'name' => 'myip',
            'slug' => 'myip',
            'country_code' => 'GR',
            'einvoice_provider' => 'gr-mydata',
            'afm' => '999999999',
            'tax_office' => 'Athens',
            'mydata_production' => false,
        ]);

        $nixpal = Company::create([
            'name' => 'nixpal',
            'slug' => 'nixpal',
            'country_code' => 'GR',
            'einvoice_provider' => 'gr-mydata',
            'afm' => '888888888',
            'tax_office' => 'Athens',
            'mydata_production' => false,
        ]);

        $estonian = Company::create([
            'name' => 'Sample EE OÜ',
            'slug' => 'sample-ee',
            'country_code' => 'EE',
            'einvoice_provider' => 'ee-peppol',
            'mydata_production' => false,
        ]);

        // (3) Admin user
        $admin = User::create([
            'name' => 'Admin',
            'email' => 'admin@ekdosi.local',
            'password' => Hash::make('password'),
            'email_verified_at' => now(),
        ]);

        $admin->companies()->attach([$myip->id, $nixpal->id, $estonian->id]);

        // (4) super_admin role per tenant, granted every permission, assigned
        //     to the admin user. Spatie's teams mode scopes roles by
        //     company_id, so we switch the active team between assignments.
        $superAdminName = ShieldUtils::getSuperAdminName(); // 'super_admin'
        $registrar = app(PermissionRegistrar::class);
        $allPermissionNames = Permission::query()->pluck('name');

        foreach ([$myip, $nixpal, $estonian] as $company) {
            $registrar->setPermissionsTeamId($company->id);
            $registrar->forgetCachedPermissions();

            $role = Role::firstOrCreate(
                ['name' => $superAdminName, 'guard_name' => 'web'],
            );
            $role->syncPermissions($allPermissionNames);

            $admin->assignRole($role);
        }
    }
}
