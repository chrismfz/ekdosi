<?php

namespace Tests\Feature\Portal;

use App\Filament\Resources\CustomerUsers\CustomerUserResource;
use App\Models\Company;
use App\Models\User;
use App\Services\TenantRoleProvisioner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The operator-side «Χρήστες πύλης» resource is super-admin only: managing who
 * may sign into the customer portal is a system-level, cross-tenant concern.
 */
class CustomerUserResourceAccessTest extends TestCase
{
    use RefreshDatabase;

    private function company(): Company
    {
        return Company::create([
            'name' => 'T', 'slug' => 'cu-'.uniqid(),
            'country_code' => 'GR', 'einvoice_provider' => 'gr-mydata', 'mydata_mode' => 'off',
        ]);
    }

    public function test_a_system_super_admin_can_access(): void
    {
        $company = $this->company();
        $user = User::create(['name' => 'Boss', 'email' => 'boss-'.uniqid().'@t.local', 'password' => bcrypt('x')]);
        $user->companies()->attach($company->id);
        app(TenantRoleProvisioner::class)->assignSuperAdmin($user, $company);

        $this->actingAs($user);
        $this->assertTrue(CustomerUserResource::canAccess());
    }

    public function test_a_plain_operator_cannot_access(): void
    {
        $company = $this->company();
        $user = User::create(['name' => 'Op', 'email' => 'op-'.uniqid().'@t.local', 'password' => bcrypt('x')]);
        $user->companies()->attach($company->id);   // a tenant member, but NOT super_admin

        $this->actingAs($user);
        $this->assertFalse(CustomerUserResource::canAccess());
    }

    public function test_a_guest_cannot_access(): void
    {
        $this->assertFalse(CustomerUserResource::canAccess());
    }
}
