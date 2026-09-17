<?php

namespace Tests\Feature\Invoice;

use App\Filament\Resources\Invoices\Pages\ViewInvoice;
use App\Models\Company;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\InvoiceType;
use App\Models\User;
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
}
