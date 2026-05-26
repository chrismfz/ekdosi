<?php

namespace Database\Seeders;

use App\Models\Company;
use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed three tenants matching the planned production shape (myip + nixpal
     * on myDATA, one Estonian on the PEPPOL stub) and an admin user attached
     * to all three so tenant switching is testable end-to-end.
     */
    public function run(): void
    {
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

        $admin = User::create([
            'name' => 'Admin',
            'email' => 'admin@ekdosi.local',
            'password' => Hash::make('password'),
            'email_verified_at' => now(),
        ]);

        $admin->companies()->attach([$myip->id, $nixpal->id, $estonian->id]);
    }
}
