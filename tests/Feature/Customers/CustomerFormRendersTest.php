<?php

namespace Tests\Feature\Customers;

use App\Filament\Resources\Customers\Pages\CreateCustomer;
use App\Filament\Resources\Customers\Pages\EditCustomer;
use App\Models\Company;
use App\Models\Customer;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Guards the CustomerForm refactor that moved the "Άντληση από ΑΑΔΕ"
 * lookup into the shared App\Filament\Support\AadeFormFill helper — the
 * create form must still render.
 */
class CustomerFormRendersTest extends TestCase
{
    use RefreshDatabase;

    public function test_create_customer_form_renders(): void
    {
        $tenant = Company::create([
            'name' => 'Cust Form Test',
            'slug' => 'cf-'.uniqid(),
            'country_code' => 'GR',
            'einvoice_provider' => 'gr-mydata',
            'mydata_mode' => 'off',
        ]);

        $user = User::create([
            'name' => 'Op',
            'email' => 'op-'.uniqid().'@example.test',
            'password' => bcrypt('x'),
        ]);

        Gate::before(fn () => true);
        $this->actingAs($user);
        Filament::setTenant($tenant);

        Livewire::test(CreateCustomer::class)->assertOk();
    }

    public function test_edit_form_renders_for_a_whmcs_governed_immediate_flag(): void
    {
        // The «Άμεση τιμολόγηση» toggle locks read-only (disabled + lock hint) for a
        // WHMCS-linked customer on a griniaris-mapping tenant. Filament v5 EVALUATES
        // those disabled/hint closures on render, so this proves they resolve cleanly
        // (the closure-evaluation gotcha that took down invoice create once).
        $tenant = Company::create([
            'name' => 'Cust Edit Test',
            'slug' => 'ce-'.uniqid(),
            'country_code' => 'GR',
            'einvoice_provider' => 'gr-mydata',
            'mydata_mode' => 'off',
            'whmcs_custom_field_map' => ['griniaris' => 16],
        ]);

        $customer = Customer::create([
            'company_id' => $tenant->id, 'name' => 'WHMCS-linked', 'whmcs_client_id' => 555,
            'needs_immediate_invoice' => true,
        ]);

        $user = User::create([
            'name' => 'Op', 'email' => 'op-'.uniqid().'@example.test', 'password' => bcrypt('x'),
        ]);

        Gate::before(fn () => true);
        $this->actingAs($user);
        Filament::setTenant($tenant);

        Livewire::test(EditCustomer::class, ['record' => $customer->getRouteKey()])->assertOk();
    }
}
