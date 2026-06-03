<?php

namespace Tests\Feature\Customers;

use App\Filament\Resources\Customers\Pages\CustomerLedger;
use App\Filament\Resources\Customers\Pages\EditCustomer;
use App\Filament\Resources\Customers\RelationManagers\ContactsRelationManager;
use App\Models\Company;
use App\Models\Customer;
use App\Models\CustomerContact;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;
use Tests\TestCase;

class CustomerContactsTest extends TestCase
{
    use RefreshDatabase;

    private function tenant(): Company
    {
        return Company::create([
            'name' => 'T', 'slug' => 'cc-'.uniqid(),
            'country_code' => 'GR', 'einvoice_provider' => 'gr-mydata', 'mydata_mode' => 'off',
        ]);
    }

    public function test_only_one_primary_contact_per_customer(): void
    {
        $t = $this->tenant();
        $c = Customer::create(['company_id' => $t->id, 'name' => 'K']);

        $a = CustomerContact::create(['company_id' => $t->id, 'customer_id' => $c->id, 'name' => 'Λογιστήριο', 'is_primary' => true]);
        $b = CustomerContact::create(['company_id' => $t->id, 'customer_id' => $c->id, 'name' => 'Τεχνικός', 'is_primary' => true]);

        $this->assertFalse($a->fresh()->is_primary, 'The earlier primary should have been demoted.');
        $this->assertTrue($b->fresh()->is_primary);
        $this->assertSame(1, CustomerContact::where('customer_id', $c->id)->where('is_primary', true)->count());
    }

    public function test_contacts_relation_orders_primary_first(): void
    {
        $t = $this->tenant();
        $c = Customer::create(['company_id' => $t->id, 'name' => 'K']);

        CustomerContact::create(['company_id' => $t->id, 'customer_id' => $c->id, 'name' => 'Βήτα']);
        CustomerContact::create(['company_id' => $t->id, 'customer_id' => $c->id, 'name' => 'Άλφα', 'is_primary' => true]);

        $this->assertSame('Άλφα', $c->contacts()->first()->name);
    }

    public function test_relation_manager_create_stamps_company_id(): void
    {
        Gate::before(fn () => true);
        $t = $this->tenant();
        $c = Customer::create(['company_id' => $t->id, 'name' => 'K']);

        $this->actingAs(User::create(['name' => 'Op', 'email' => 'op-'.uniqid().'@t.l', 'password' => bcrypt('x')]));
        Filament::setTenant($t);

        Livewire::test(ContactsRelationManager::class, [
            'ownerRecord' => $c,
            'pageClass' => EditCustomer::class,
        ])
            ->callTableAction('create', data: [
                'name' => 'Νέα Επαφή',
                'role' => 'Λογιστήριο',
                'email' => 'acc@x.gr',
                'phone' => '2310123456',
                'is_primary' => true,
            ])
            ->assertHasNoTableActionErrors();

        $contact = CustomerContact::where('customer_id', $c->id)->first();
        $this->assertNotNull($contact);
        $this->assertSame($t->id, $contact->company_id);
        $this->assertSame('Νέα Επαφή', $contact->name);
    }

    public function test_kartela_renders_contacts_section(): void
    {
        Gate::before(fn () => true);
        $t = $this->tenant();
        $c = Customer::create(['company_id' => $t->id, 'name' => 'K']);
        CustomerContact::create([
            'company_id' => $t->id, 'customer_id' => $c->id,
            'name' => 'Μαρία Λογίστρια', 'role' => 'Λογιστήριο', 'is_primary' => true,
        ]);

        $this->actingAs(User::create(['name' => 'Op', 'email' => 'op-'.uniqid().'@t.l', 'password' => bcrypt('x')]));
        Filament::setTenant($t);

        Livewire::test(CustomerLedger::class, ['record' => $c->id])
            ->assertStatus(200)
            ->assertSee('Επαφές')
            ->assertSee('Μαρία Λογίστρια')
            ->assertSee('Λογιστήριο');
    }
}
