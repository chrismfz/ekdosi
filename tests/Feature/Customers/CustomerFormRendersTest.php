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

    public function test_governed_immediate_toggle_is_not_wiped_on_save_but_editable_when_not_governed(): void
    {
        // The load-bearing claim of the lock: disabled ⇒ not dehydrated ⇒ a save can't
        // overwrite the WHMCS-mirrored value. And a NON-governed customer stays editable.
        $tenant = Company::create([
            'name' => 'Cust Save Test',
            'slug' => 'cs-'.uniqid(),
            'country_code' => 'GR',
            'einvoice_provider' => 'gr-mydata',
            'mydata_mode' => 'off',
            'whmcs_custom_field_map' => ['griniaris' => 16],
        ]);
        $user = User::create([
            'name' => 'Op', 'email' => 'op-'.uniqid().'@example.test', 'password' => bcrypt('x'),
        ]);
        Gate::before(fn () => true);
        $this->actingAs($user);
        Filament::setTenant($tenant);

        // Governed (WHMCS-linked, mirrored ON): a save that tries to flip it OFF is ignored.
        $governed = Customer::create([
            'company_id' => $tenant->id, 'name' => 'WHMCS-linked', 'whmcs_client_id' => 555,
            'needs_immediate_invoice' => true,
        ]);
        Livewire::test(EditCustomer::class, ['record' => $governed->getRouteKey()])
            ->fillForm(['needs_immediate_invoice' => false])
            ->call('save')
            ->assertHasNoFormErrors();
        $this->assertTrue($governed->fresh()->needs_immediate_invoice, 'locked field survives a save (not dehydrated)');

        // Non-governed (no WHMCS link): the operator's toggle is honoured.
        $manual = Customer::create([
            'company_id' => $tenant->id, 'name' => 'Manual', 'needs_immediate_invoice' => false,
        ]);
        Livewire::test(EditCustomer::class, ['record' => $manual->getRouteKey()])
            ->fillForm(['needs_immediate_invoice' => true])
            ->call('save')
            ->assertHasNoFormErrors();
        $this->assertTrue($manual->fresh()->needs_immediate_invoice, 'non-governed toggle saves normally');
    }
}
