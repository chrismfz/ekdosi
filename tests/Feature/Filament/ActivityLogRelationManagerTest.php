<?php

namespace Tests\Feature\Filament;

use App\Filament\RelationManagers\ActivityLogRelationManager;
use App\Filament\Resources\Customers\Pages\EditCustomer;
use App\Models\Company;
use App\Models\Customer;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The shared «Ιστορικό» relation manager renders its audit rows (incl. the
 * custom changes-diff column) without error and lists the record's activities.
 */
class ActivityLogRelationManagerTest extends TestCase
{
    use RefreshDatabase;

    public function test_history_table_renders_for_a_customer(): void
    {
        Gate::before(fn () => true);

        $company = Company::create([
            'name' => 'Acme', 'slug' => 'acme-'.uniqid(),
            'country_code' => 'GR', 'einvoice_provider' => 'gr-mydata', 'mydata_mode' => 'off',
        ]);
        $user = User::create([
            'name' => 'Op', 'email' => 'op-'.uniqid().'@test.local', 'password' => bcrypt('x'),
        ]);
        $this->actingAs($user);

        $customer = Customer::create([
            'company_id' => $company->id, 'name' => 'Πελάτης ΑΕ', 'afm' => '123456789',
        ]);
        $customer->refresh();
        $customer->update(['name' => 'Νέα Επωνυμία']);

        Livewire::test(ActivityLogRelationManager::class, [
            'ownerRecord' => $customer,
            'pageClass' => EditCustomer::class,
        ])
            ->assertSuccessful()
            ->assertSee('Δημιουργία')
            ->assertSee('Τροποποίηση')
            ->assertSee('Op')          // causer name
            ->assertSee('Νέα Επωνυμία'); // the new value in the changes diff
    }
}
