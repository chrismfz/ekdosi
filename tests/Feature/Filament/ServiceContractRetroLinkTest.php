<?php

namespace Tests\Feature\Filament;

use App\Enums\ServiceContractStatus;
use App\Filament\Resources\ServiceContracts\Pages\ViewServiceContract;
use App\Filament\Resources\ServiceContracts\RelationManagers\RenewalsRelationManager;
use App\Models\Company;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\InvoiceType;
use App\Models\ServiceContract;
use App\Models\User;
use Carbon\Carbon;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * #11 retro-link: «Σύνδεση υπάρχοντος παραστατικού» attaches an already-issued
 * invoice of the same customer to a contract (the manual-VM case) by setting only
 * invoices.service_contract_id. Tenant-safe: only the contract's own tenant +
 * customer, issued, unlinked invoices are linkable — re-checked at the write.
 */
class ServiceContractRetroLinkTest extends TestCase
{
    use RefreshDatabase;

    private Company $tenant;

    private Customer $customer;

    private ServiceContract $contract;

    private InvoiceType $type;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-09-17 12:00:00'));

        $this->tenant = Company::create([
            'name' => 'SC', 'slug' => 'sc-'.uniqid(),
            'country_code' => 'GR', 'einvoice_provider' => 'gr-mydata', 'mydata_mode' => 'off',
        ]);
        $this->customer = Customer::create(['company_id' => $this->tenant->id, 'name' => 'ΠΕΛΑΤΗΣ ΑΕ']);
        $this->type = InvoiceType::create([
            'company_id' => $this->tenant->id, 'code' => 'ΤΠΥ', 'name' => 'ΤΠΥ', 'invcount' => 1,
        ]);
        $this->contract = ServiceContract::create([
            'company_id' => $this->tenant->id, 'customer_id' => $this->customer->id,
            'invoice_type_id' => $this->type->id, 'billing_cycle' => 'annual',
            'amount' => 4800, 'vat_percent' => 24, 'status' => ServiceContractStatus::Active->value,
        ]);

        $operator = User::create(['name' => 'Op', 'email' => 'op-'.uniqid().'@t.local', 'password' => bcrypt('x')]);
        $this->tenant->users()->attach($operator->id);
        Gate::before(fn () => true);
        $this->actingAs($operator);
        Filament::setTenant($this->tenant);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function issuedInvoice(array $overrides = []): Invoice
    {
        static $n = 0;
        $n++;

        return Invoice::create(array_merge([
            'company_id' => $this->tenant->id,
            'customer_id' => $this->customer->id,
            'invoice_type_id' => $this->type->id,
            'code' => $n, 'invcode' => 'ΤΠΥ'.$n,
            'issued_at' => '2026-09-17 09:13:00',
            'local_status' => 'active',
            'net_total' => 4800, 'gross_total' => 5952, 'payable_total' => 5952,
        ], $overrides));
    }

    public function test_links_an_existing_invoice_and_stamps_last_invoiced(): void
    {
        $invoice = $this->issuedInvoice();

        Livewire::test(ViewServiceContract::class, ['record' => $this->contract->id, 'tenant' => $this->tenant->slug])
            ->callAction('link_invoice', data: [
                'invoice_id' => $invoice->id,
                'stamp_last_invoiced' => true,
            ])
            ->assertHasNoActionErrors();

        $this->assertSame($this->contract->id, $invoice->refresh()->service_contract_id);
        $this->assertSame('2026-09-17', $this->contract->refresh()->last_invoiced_at->format('Y-m-d'));
    }

    public function test_does_not_stamp_last_invoiced_when_toggle_off(): void
    {
        $invoice = $this->issuedInvoice();

        Livewire::test(ViewServiceContract::class, ['record' => $this->contract->id, 'tenant' => $this->tenant->slug])
            ->callAction('link_invoice', data: [
                'invoice_id' => $invoice->id,
                'stamp_last_invoiced' => false,
            ]);

        $this->assertSame($this->contract->id, $invoice->refresh()->service_contract_id);
        $this->assertNull($this->contract->refresh()->last_invoiced_at);
    }

    public function test_a_foreign_tenant_invoice_cannot_be_linked(): void
    {
        // An invoice in another company must not be attachable — its display
        // would then leak, and it would corrupt the other tenant's document.
        $other = Company::create([
            'name' => 'Other', 'slug' => 'other-'.uniqid(),
            'country_code' => 'GR', 'einvoice_provider' => 'gr-mydata', 'mydata_mode' => 'off',
        ]);
        $foreignCustomer = Customer::create(['company_id' => $other->id, 'name' => 'Ξένος']);
        $foreignType = InvoiceType::create(['company_id' => $other->id, 'code' => 'ΤΠΥ', 'name' => 'ΤΠΥ', 'invcount' => 1]);
        $foreign = Invoice::create([
            'company_id' => $other->id, 'customer_id' => $foreignCustomer->id, 'invoice_type_id' => $foreignType->id,
            'code' => 1, 'invcode' => 'X1', 'issued_at' => now(), 'local_status' => 'active',
            'net_total' => 10, 'gross_total' => 12, 'payable_total' => 12,
        ]);

        // Two layers reject it: the Select validates the value against its
        // tenant+customer-scoped resolver (this error) AND the action re-checks
        // the same predicate at the write. Either way the foreign row is untouched.
        Livewire::test(ViewServiceContract::class, ['record' => $this->contract->id, 'tenant' => $this->tenant->slug])
            ->callAction('link_invoice', data: ['invoice_id' => $foreign->id])
            ->assertHasActionErrors(['invoice_id']);

        $this->assertNull($foreign->refresh()->service_contract_id);
    }

    public function test_an_already_linked_invoice_is_not_relinked(): void
    {
        $otherContract = ServiceContract::create([
            'company_id' => $this->tenant->id, 'customer_id' => $this->customer->id,
            'invoice_type_id' => $this->type->id, 'billing_cycle' => 'annual',
            'amount' => 1, 'vat_percent' => 24, 'status' => ServiceContractStatus::Active->value,
        ]);
        $invoice = $this->issuedInvoice(['service_contract_id' => $otherContract->id]);

        Livewire::test(ViewServiceContract::class, ['record' => $this->contract->id, 'tenant' => $this->tenant->slug])
            ->callAction('link_invoice', data: ['invoice_id' => $invoice->id])
            ->assertHasActionErrors(['invoice_id']);

        // Stays with its original contract — an already-linked invoice isn't eligible.
        $this->assertSame($otherContract->id, $invoice->refresh()->service_contract_id);
    }

    public function test_a_cancelled_invoice_cannot_be_linked(): void
    {
        // Linking a cancelled document + stamping «last invoiced» would wrongly
        // suppress the first renewal's setup fee — so it's not eligible (not live).
        $cancelled = $this->issuedInvoice(['local_status' => 'cancelled']);

        Livewire::test(ViewServiceContract::class, ['record' => $this->contract->id, 'tenant' => $this->tenant->slug])
            ->callAction('link_invoice', data: ['invoice_id' => $cancelled->id, 'stamp_last_invoiced' => true])
            ->assertHasActionErrors(['invoice_id']);

        $this->assertNull($cancelled->refresh()->service_contract_id);
        $this->assertNull($this->contract->refresh()->last_invoiced_at);
    }

    public function test_renewals_tab_lists_the_contract_invoices(): void
    {
        $linked = $this->issuedInvoice(['service_contract_id' => $this->contract->id, 'invcode' => 'ΤΠΥ777']);
        $unrelated = $this->issuedInvoice(); // same customer, NOT linked

        Livewire::test(RenewalsRelationManager::class, [
            'ownerRecord' => $this->contract,
            'pageClass' => ViewServiceContract::class,
        ])
            ->assertCanSeeTableRecords([$linked])
            ->assertCanNotSeeTableRecords([$unrelated])
            ->assertSee('ΤΠΥ777');
    }
}
