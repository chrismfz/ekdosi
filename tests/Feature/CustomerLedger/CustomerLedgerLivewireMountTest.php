<?php

namespace Tests\Feature\CustomerLedger;

use App\Filament\Resources\Customers\Pages\CustomerLedger;
use App\Models\Company;
use App\Models\Customer;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Reproduce the production 404. Boot the page exactly as Filament does:
 * - auth a user
 * - bind the panel tenant (Filament::setTenant)
 * - Livewire::test the page with the record param
 *
 * If this test 404s, we can see WHERE in the stack the abort fires.
 * If it passes, the prod 404 is config / env / middleware, not the code.
 */
class CustomerLedgerLivewireMountTest extends TestCase
{
    use RefreshDatabase;

    public function test_page_mounts_for_logged_in_user_in_correct_tenant(): void
    {
        $tenant = Company::create([
            'name' => 'Test',
            'slug' => 'mount-'.uniqid(),
            'country_code' => 'GR',
            'einvoice_provider' => 'gr-mydata',
            'mydata_mode' => 'off',
        ]);

        $customer = Customer::create([
            'company_id' => $tenant->id,
            'name' => 'Καρτέλα Test',
        ]);

        $user = User::create([
            'name' => 'Op',
            'email' => 'op-'.uniqid().'@example.test',
            'password' => bcrypt('x'),
        ]);

        // Bypass the policy gate in mount() — we're testing the
        // page-mount mechanics (the TypeError on $record), NOT the
        // CustomerPolicy. In production an operator with view
        // permission satisfies the policy; the test user doesn't
        // have Shield-generated permissions.
        \Illuminate\Support\Facades\Gate::before(fn () => true);

        $this->actingAs($user);

        // Bind the Filament tenant exactly like the IdentifyTenant
        // middleware would after the {tenant:slug} route param resolves.
        Filament::setTenant($tenant);

        // Mount the page with the record param Livewire receives from
        // the route. This exercises canAccess + mountCanAuthorizeAccess
        // + mountCanAuthorizeResourceAccess + mount().
        $response = Livewire::test(CustomerLedger::class, ['record' => $customer->id]);

        // If we reached here without aborting, the page mounted OK.
        $response->assertStatus(200);
        $this->assertSame($customer->id, $response->get('record')->id);
    }
}
