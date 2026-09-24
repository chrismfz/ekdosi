<?php

namespace Tests\Feature\Invoice;

use App\Filament\Resources\Invoices\Pages\ViewInvoice;
use App\Models\Company;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\InvoiceLine;
use App\Models\InvoiceType;
use App\Models\PaymentMethod;
use App\Models\User;
use App\Services\InvoiceNumberer;
use App\Services\RecomputeInvoiceTotals;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * When does a document get its ΑΑ, and when may it go back to draft?
 *
 * Invoice::isIssuedAtFinalize(): on a tenant that doesn't file (Nixpal) and for an
 * informal series, finalize IS the issuance (numbered there). A filing tenant's
 * fiscal series is numbered at the send — including one with a MISSING myDATA
 * type, which is a config error surfaced at submit, never a silently unfiled issue.
 *
 * A document issued WITH its number at finalize never goes back to draft (neither
 * «Επαναφορά σε πρόχειρο» nor cancel → «Επαναφορά»). A filing tenant's numbered fiscal
 * document isn't issued until its MARK: after a definitive rejection it may still
 * revert and be fixed — but never mid-send (in-doubt).
 */
class IssueAtFinalizeTest extends TestCase
{
    use RefreshDatabase;

    private Company $tenant;

    private Customer $customer;

    private PaymentMethod $credit;

    private InvoiceType $filable;

    private InvoiceType $noMyData;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Company::create([
            'name' => 'Filing test', 'slug' => 'filing-'.uniqid(), 'country_code' => 'GR',
            'einvoice_provider' => 'gr-mydata', 'mydata_mode' => 'sandbox', 'afm' => '800561849',
        ]);
        $this->customer = Customer::create([
            'company_id' => $this->tenant->id, 'name' => 'Πελάτης', 'afm' => '123456789', 'email' => 'c@example.test',
        ]);
        $this->credit = PaymentMethod::create(['company_id' => $this->tenant->id, 'description' => 'Πίστωση', 'due_days' => 30]);
        $this->filable = InvoiceType::create([
            'company_id' => $this->tenant->id, 'code' => 'ΤΠΥ', 'name' => 'Τιμολόγιο Παροχής', 'invcount' => 1, 'mydata_type' => '2.1',
        ]);
        $this->noMyData = InvoiceType::create([
            'company_id' => $this->tenant->id, 'code' => 'ΛΘΣ', 'name' => 'Ξεχασμένος τύπος', 'invcount' => 1,
        ]);
    }

    public function test_on_a_non_filing_tenant_finalize_issues_and_a_numbered_document_never_reverts(): void
    {
        $this->tenant->forceFill(['einvoice_provider' => 'none', 'mydata_mode' => 'off'])->save();
        $this->actingAsOperator();
        $draft = $this->document($this->filable);
        $this->assertTrue($draft->isIssuedAtFinalize());

        Livewire::test(ViewInvoice::class, ['record' => $draft->getKey()])->callAction('finalize');

        $this->assertSame('ΤΠΥ1', $draft->fresh()->invcode);
        Livewire::test(ViewInvoice::class, ['record' => $draft->getKey()])
            ->assertActionHidden('revert_to_draft');

        // No detour either: cancel → «Επαναφορά» brings it back ACTIVE, not an
        // editable draft under ΤΠΥ1.
        Livewire::test(ViewInvoice::class, ['record' => $draft->getKey()])
            ->callAction('cancel_local', data: ['cancel_reason' => 'λάθος'])
            ->callAction('revive');
        $this->assertSame('active', $draft->fresh()->local_status);
        $this->assertSame('ΤΠΥ1', $draft->fresh()->invcode);

        // An active document WITHOUT a number may still revert.
        $unnumbered = $this->document($this->filable, status: 'active');
        Livewire::test(ViewInvoice::class, ['record' => $unnumbered->getKey()])
            ->assertActionVisible('revert_to_draft');
    }

    public function test_on_a_filing_tenant_a_fiscal_series_is_numbered_at_the_send_and_reverts_until_then(): void
    {
        $this->actingAsOperator();
        $draft = $this->document($this->filable);
        $this->assertFalse($draft->isIssuedAtFinalize());

        Livewire::test(ViewInvoice::class, ['record' => $draft->getKey()])->callAction('finalize');

        $this->assertNull($draft->fresh()->code);
        Livewire::test(ViewInvoice::class, ['record' => $draft->getKey()])
            ->assertActionVisible('submit_to_mydata')
            ->assertActionVisible('revert_to_draft');

        // Numbered at the send but definitively REJECTED (not issued — no MARK): it
        // may still revert, be fixed and resubmitted under the same ΑΑ.
        app(InvoiceNumberer::class)->assign($draft->fresh());
        Livewire::test(ViewInvoice::class, ['record' => $draft->getKey()])
            ->assertActionVisible('revert_to_draft');

        // Mid-send (in-doubt: it may already hold a MARK) — never.
        $draft->forceFill(['mydata_pending_since' => now()])->saveQuietly();
        Livewire::test(ViewInvoice::class, ['record' => $draft->getKey()])
            ->assertActionHidden('revert_to_draft');
    }

    public function test_a_series_missing_its_mydata_type_is_never_silently_issued_on_a_filing_tenant(): void
    {
        // A missing myDATA type on a filing tenant is a config error: the submit
        // refuses (and hands the ΑΑ back). It must not be numbered and issued at
        // finalize as if it were «not sent by design» — that is the informal flag.
        $this->actingAsOperator();
        $draft = $this->document($this->noMyData);
        $this->assertFalse($draft->isIssuedAtFinalize());

        Livewire::test(ViewInvoice::class, ['record' => $draft->getKey()])->callAction('finalize');

        $this->assertNull($draft->fresh()->code);
        Livewire::test(ViewInvoice::class, ['record' => $draft->getKey()])
            ->assertActionVisible('submit_to_mydata');
    }

    private function document(InvoiceType $type, string $status = 'draft'): Invoice
    {
        $invoice = Invoice::create([
            'company_id' => $this->tenant->id, 'invoice_type_id' => $type->id, 'customer_id' => $this->customer->id,
            'payment_method_id' => $this->credit->id, 'issued_at' => now(), 'local_status' => $status,
        ]);
        InvoiceLine::create([
            'company_id' => $this->tenant->id, 'invoice_id' => $invoice->id,
            'qty' => 1, 'price_per_item' => 100, 'vat_percent' => 24, 'product_descr' => 'Υπηρεσία',
        ]);

        return app(RecomputeInvoiceTotals::class)($invoice)->fresh();
    }

    private function actingAsOperator(): void
    {
        Gate::before(fn () => true);
        $this->actingAs(User::create(['name' => 'Op', 'email' => 'op-'.uniqid().'@example.test', 'password' => bcrypt('x')]));
        Filament::setTenant($this->tenant);
    }
}
