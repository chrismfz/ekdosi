<?php

namespace Tests\Feature\Filament;

use App\Enums\BillingCycle;
use App\Enums\ServiceContractStatus;
use App\Filament\Resources\Products\Pages\CreateProduct;
use App\Filament\Resources\ServiceContracts\Pages\CreateServiceContract;
use App\Filament\Resources\ServiceContracts\Pages\ListServiceContracts;
use App\Filament\Resources\ServiceContracts\Pages\ViewServiceContract;
use App\Models\Company;
use App\Models\Customer;
use App\Models\InvoiceType;
use App\Models\ServiceContract;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Smoke tests that the recurring-services Filament screens boot: the contract
 * list / create / view pages and the Product form (which gained the recurring
 * price-matrix section). A Livewire mount proves each schema is valid + renders.
 */
class ServiceContractFormRenderTest extends TestCase
{
    use RefreshDatabase;

    private Company $tenant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Company::create([
            'name' => 'Svc', 'slug' => 'svc-'.uniqid(),
            'country_code' => 'GR', 'einvoice_provider' => 'gr-mydata', 'mydata_mode' => 'sandbox',
            'afm' => '800000000',
        ]);
        InvoiceType::create([
            'company_id' => $this->tenant->id, 'code' => 'TPY', 'name' => 'Τιμολόγιο',
            'invcount' => 1, 'show_on_menu' => true,
        ]);

        $user = User::create([
            'name' => 'Op', 'email' => 'svc-'.uniqid().'@example.test', 'password' => bcrypt('x'),
        ]);
        Gate::before(fn () => true);
        $this->actingAs($user);
        Filament::setTenant($this->tenant);
    }

    public function test_list_contracts_renders(): void
    {
        Livewire::test(ListServiceContracts::class)->assertOk();
    }

    public function test_create_contract_form_renders(): void
    {
        Livewire::test(CreateServiceContract::class)->assertOk();
    }

    public function test_view_contract_with_lifecycle_actions_renders(): void
    {
        $customer = Customer::create(['company_id' => $this->tenant->id, 'name' => 'Π', 'afm' => '123456789']);
        $contract = ServiceContract::create([
            'company_id' => $this->tenant->id, 'customer_id' => $customer->id,
            'billing_cycle' => BillingCycle::Annual->value, 'amount' => 100, 'vat_percent' => 24,
            'status' => ServiceContractStatus::Active->value, 'next_due_date' => now(),
        ]);

        Livewire::test(ViewServiceContract::class, ['record' => $contract->getKey()])->assertOk();
    }

    public function test_view_contract_shows_its_notes_escaped_with_line_breaks(): void
    {
        $customer = Customer::create(['company_id' => $this->tenant->id, 'name' => 'Π', 'afm' => '123456789']);
        $contract = ServiceContract::create([
            'company_id' => $this->tenant->id, 'customer_id' => $customer->id,
            'billing_cycle' => BillingCycle::Annual->value, 'amount' => 100, 'vat_percent' => 24,
            'status' => ServiceContractStatus::Active->value, 'next_due_date' => now(),
            'notes' => "84.54.49.221\n<b>192.168.144.10</b>",
        ]);

        Livewire::test(ViewServiceContract::class, ['record' => $contract->getKey()])
            ->assertOk()
            ->assertSeeHtml('84.54.49.221<br />')
            ->assertSeeHtml('&lt;b&gt;192.168.144.10&lt;/b&gt;')
            ->assertDontSeeHtml('<b>192.168.144.10</b>');
    }

    public function test_view_contract_without_notes_hides_the_notes_section(): void
    {
        $customer = Customer::create(['company_id' => $this->tenant->id, 'name' => 'Π', 'afm' => '123456789']);
        $contract = ServiceContract::create([
            'company_id' => $this->tenant->id, 'customer_id' => $customer->id,
            'billing_cycle' => BillingCycle::Annual->value, 'amount' => 100, 'vat_percent' => 24,
            'status' => ServiceContractStatus::Active->value, 'next_due_date' => now(),
        ]);

        Livewire::test(ViewServiceContract::class, ['record' => $contract->getKey()])
            ->assertOk()
            ->assertDontSee('Σημειώσεις');
    }

    public function test_product_form_with_recurring_section_renders(): void
    {
        Livewire::test(CreateProduct::class)->assertOk();
    }
}
