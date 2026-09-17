<?php

namespace Tests\Feature\Invoice;

use App\Filament\Resources\Invoices\Pages\ViewInvoice;
use App\Models\Company;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\InvoiceType;
use App\Models\User;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The «Live customer record» entry on the invoice view is a COMPACT LINK to the
 * live customer, not the full (often 5–7 line) company name — that name wrapped to
 * many lines and, next to the frozen «Name on invoice» snapshot, was pure height.
 * Keeping it a short link keeps the Customer card tight (reclaims vertical space).
 */
class InvoiceCustomerLinkInfolistTest extends TestCase
{
    use RefreshDatabase;

    private function boot(Company $tenant): void
    {
        Gate::before(fn () => true);
        $this->actingAs(User::create([
            'name' => 'Op', 'email' => 'op-'.uniqid().'@example.test', 'password' => bcrypt('x'),
        ]));
        Filament::setTenant($tenant);
    }

    private function tenant(): Company
    {
        return Company::create([
            'name' => 'Prov', 'slug' => 'cl-'.uniqid(), 'country_code' => 'GR',
            'einvoice_provider' => 'gr-mydata', 'mydata_mode' => 'off', 'afm' => '800561849',
        ]);
    }

    private function invoice(Company $tenant, ?int $customerId): Invoice
    {
        $type = InvoiceType::create([
            'company_id' => $tenant->id, 'code' => 'TPY', 'name' => 'ΤΠΥ', 'invcount' => 1, 'mydata_type' => '2.1',
        ]);

        return Invoice::create([
            'company_id' => $tenant->id, 'invoice_type_id' => $type->id, 'customer_id' => $customerId,
            'code' => 1, 'invcode' => 'TPY100', 'issued_at' => now(), 'local_status' => 'active',
            'company_name' => 'ΠΟΛΥ ΜΕΓΑΛΗ ΕΠΩΝΥΜΙΑ ΕΙΣΑΓΩΓΕΣ ΕΜΠΟΡΙΟ ΕΙΔΩΝ ΑΝΩΝΥΜΗ ΕΤΑΙΡΙΑ',
            'vat_no' => '123456789', 'occupation' => 'ΥΠΗΡΕΣΙΕΣ ΔΟΚΙΜΗΣ', 'address1' => 'ΟΔΟΣ ΔΟΚΙΜΗΣ 123',
            'city' => 'ΑΘΗΝΑ', 'postcode' => '10679', 'country' => 'GR',
        ]);
    }

    public function test_linked_customer_shows_a_compact_link_to_the_live_record(): void
    {
        $tenant = $this->tenant();
        $this->boot($tenant);
        $customer = Customer::create([
            'company_id' => $tenant->id,
            'name' => 'ΠΟΛΥ ΜΕΓΑΛΗ ΕΠΩΝΥΜΙΑ ΕΙΣΑΓΩΓΕΣ ΕΜΠΟΡΙΟ ΕΙΔΩΝ ΑΝΩΝΥΜΗ ΕΤΑΙΡΙΑ',
        ]);
        $invoice = $this->invoice($tenant, $customer->id);

        Livewire::test(ViewInvoice::class, ['record' => $invoice->id, 'tenant' => $tenant->slug])
            ->assertSee('Άνοιγμα εγγραφής')                            // the compact link label
            ->assertSee('/customers/'.$customer->id.'/edit', false);   // links to the live record
    }

    public function test_invoice_without_a_linked_customer_shows_no_link(): void
    {
        $tenant = $this->tenant();
        $this->boot($tenant);
        $invoice = $this->invoice($tenant, null);

        Livewire::test(ViewInvoice::class, ['record' => $invoice->id, 'tenant' => $tenant->slug])
            ->assertDontSee('Άνοιγμα εγγραφής');
    }

    public function test_soft_deleted_customer_shows_no_broken_link(): void
    {
        // The FK (customer_id) survives a SoftDeletes on the Customer, but the
        // `customer` relation resolves null (soft-delete scope) and route-model
        // binding 404s a trashed record. The entry must gate on the relation and
        // degrade to «—», not render a link that dead-ends.
        $tenant = $this->tenant();
        $this->boot($tenant);
        $customer = Customer::create(['company_id' => $tenant->id, 'name' => 'Πρώην Πελάτης ΑΕ']);
        $invoice = $this->invoice($tenant, $customer->id);
        $customer->delete(); // soft delete

        Livewire::test(ViewInvoice::class, ['record' => $invoice->id, 'tenant' => $tenant->slug])
            ->assertDontSee('Άνοιγμα εγγραφής')                        // no label
            ->assertDontSee('/customers/'.$customer->id.'/edit', false); // and no dead-end URL
    }

    public function test_the_full_details_modal_action_is_present_and_address_is_not_inline(): void
    {
        // The address/VIES/city/postcode/country moved into the «Πλήρη στοιχεία»
        // modal to keep the card compact; only name + ΑΦΜ (+ live link) stay inline.
        $tenant = $this->tenant();
        $this->boot($tenant);
        $invoice = $this->invoice($tenant, null);

        Livewire::test(ViewInvoice::class, ['record' => $invoice->id, 'tenant' => $tenant->slug])
            ->assertSee('Πλήρη στοιχεία')            // the modal trigger renders
            ->assertSee('123456789')                  // the ΑΦΜ value stays inline
            ->assertDontSee('ΟΔΟΣ ΔΟΚΙΜΗΣ 123')       // address is no longer inline (modal is lazy)
            // the action is actually wired on the Customer section (not just text) and opens
            ->assertActionVisible(TestAction::make('customer_details')->schemaComponent('customer'))
            ->mountAction(TestAction::make('customer_details')->schemaComponent('customer'))
            ->assertActionMounted(TestAction::make('customer_details')->schemaComponent('customer'));
    }

    public function test_customer_details_modal_view_renders_the_full_snapshot(): void
    {
        // The modal Blade reads the invoice snapshot columns — assert it surfaces the
        // address block that was removed from the inline card.
        $tenant = $this->tenant();
        $invoice = $this->invoice($tenant, null);

        $html = view('filament.invoices.customer-details', ['invoice' => $invoice])->render();

        $this->assertStringContainsString('ΟΔΟΣ ΔΟΚΙΜΗΣ 123', $html);
        $this->assertStringContainsString('ΑΘΗΝΑ', $html);
        $this->assertStringContainsString('10679', $html);
        $this->assertStringContainsString('ΥΠΗΡΕΣΙΕΣ ΔΟΚΙΜΗΣ', $html); // Δραστηριότητα moved into the modal
        // Name + ΑΦΜ stay INLINE on the card, so they are NOT duplicated in the modal.
        $this->assertStringNotContainsString('123456789', $html);
    }
}
