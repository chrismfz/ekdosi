<?php

namespace Tests\Feature\Filament;

use App\Filament\Resources\Invoices\Pages\ViewInvoice;
use App\Models\Company;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\InvoiceType;
use App\Models\User;
use App\Models\VatCategory;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Production bug: clicking «Έκδοση πιστωτικού» on an invoice returned
 * «There was an error while attempting to load this page» with NO logged PHP
 * exception. Root cause: the modal's per-line Repeater held a Placeholder named
 * «label» whose content read `$get('label')` — a SELF-REFERENCE that recurses
 * under Filament v5 (the field resolves its own content, which reads itself…),
 * hanging the action mount. The sibling «Ακύρωση μέσω πιστωτικού» modal worked
 * precisely because it has no Repeater. These tests mount the action (the exact
 * failing step) and drive it end-to-end.
 */
class IssueCreditNoteModalTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Gate::before(fn () => true);
        $this->actingAs(User::create([
            'name' => 'Admin', 'email' => 'a-'.uniqid().'@test.local', 'password' => bcrypt('x'),
        ]));
    }

    public function test_opening_the_issue_credit_note_modal_does_not_hang_or_error(): void
    {
        $invoice = $this->retailInvoice();

        // mountAction is the step that hung in production (self-referential Placeholder).
        Livewire::test(ViewInvoice::class, ['record' => $invoice->getRouteKey()])
            ->assertActionVisible('issue_credit_note')
            ->mountAction('issue_credit_note')
            ->assertHasNoActionErrors();
    }

    public function test_issuing_a_credit_note_from_the_modal_creates_a_linked_credit(): void
    {
        $invoice = $this->retailInvoice();
        $creditType = InvoiceType::where('company_id', $invoice->company_id)->where('mydata_type', '11.4')->firstOrFail();
        $lineId = $invoice->lines->first()->id;

        Livewire::test(ViewInvoice::class, ['record' => $invoice->getRouteKey()])
            ->mountAction('issue_credit_note')
            ->setActionData([
                'credit_type_id' => $creditType->id,
                'lines' => [['line_id' => $lineId, 'label' => 'x', 'qty' => 1.0]],
                'submit_now' => false,
            ])
            ->callMountedAction()
            ->assertHasNoActionErrors();

        $credit = Invoice::where('company_id', $invoice->company_id)->where('credited_invoice_id', $invoice->id)->first();
        $this->assertNotNull($credit, 'a credit note linked to the original was created');
        $this->assertSame('11.4', $credit->invoiceType->mydata_type);
    }

    private function retailInvoice(): Invoice
    {
        $tenant = Company::create([
            'name' => 'Direct ΑΕ', 'slug' => 'dir-'.uniqid(), 'country_code' => 'GR',
            'einvoice_provider' => 'gr-mydata', 'mydata_mode' => 'off', 'afm' => '800561849',
        ]);
        Filament::setTenant($tenant);

        $customer = Customer::create(['company_id' => $tenant->id, 'name' => 'MYTECH', 'afm' => '114405515']);
        VatCategory::create(['company_id' => $tenant->id, 'description' => '24%', 'rate' => 24, 'is_default' => true]);
        $alp = InvoiceType::create(['company_id' => $tenant->id, 'code' => 'ΑΛΠ', 'name' => 'ΑΛΠ', 'invcount' => 12, 'mydata_type' => '11.1']);
        InvoiceType::create(['company_id' => $tenant->id, 'code' => 'ΠΙΛ', 'name' => 'Πιστωτικό Λιανικής', 'invcount' => 1, 'mydata_type' => '11.4', 'is_credit' => true]);

        $invoice = Invoice::create([
            'company_id' => $tenant->id, 'invcode' => 'ΑΛΠ12', 'code' => 12, 'invoice_type_id' => $alp->id,
            'customer_id' => $customer->id, 'issued_at' => now(), 'local_status' => 'active',
            'company_name' => 'MYTECH', 'vat_no' => '114405515', 'series' => 'ΑΛΠ', 'aa' => 12,
        ]);
        $invoice->lines()->create(['company_id' => $tenant->id, 'product_descr' => 'Υπηρεσία', 'qty' => 1, 'price_per_item' => 20, 'vat_percent' => 24]);

        return $invoice->fresh('lines');
    }
}
