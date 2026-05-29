<?php

namespace Tests\Feature\Customers;

use App\Filament\Resources\Customers\Pages\CreateCustomer;
use App\Models\Company;
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
}
