<?php

namespace Tests\Feature\Invoice;

use App\Models\Company;
use App\Models\Invoice;
use App\Models\InvoiceType;
use App\Services\InvoicePdfRenderer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The «Σχετικά παραστατικά» block on the invoice PDF — mirrors the Filament panel
 * section: an original fully reversed by a credit note shows «Ακυρώθηκε με
 * πιστωτικό» + the credit note's code so the customer can tie the two; the credit
 * note shows which original it reverses. Customer-safe (no operator names / diffs).
 */
class InvoicePdfRelatedDocsTest extends TestCase
{
    use RefreshDatabase;

    private Company $tenant;

    private InvoiceType $type;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = Company::create([
            'name' => 'Rel', 'slug' => 'rel-'.uniqid(),
            'country_code' => 'GR', 'einvoice_provider' => 'gr-mydata', 'mydata_mode' => 'off',
        ]);
        $this->type = InvoiceType::create([
            'company_id' => $this->tenant->id, 'code' => 'TPY', 'name' => 'ΤΠΥ', 'invcount' => 1,
        ]);
    }

    private function invoice(array $overrides = []): Invoice
    {
        return Invoice::create(array_merge([
            'company_id' => $this->tenant->id,
            'invoice_type_id' => $this->type->id,
            'code' => 1,
            'invcode' => 'TPY6656',
            'issued_at' => now(),
            'local_status' => 'active',
        ], $overrides));
    }

    private function renderHtml(Invoice $invoice): string
    {
        $invoice->loadMissing(['lines', 'invoiceType', 'customer', 'company', 'creditNotes', 'creditedInvoice', 'deliveryNotes']);
        $renderer = app(InvoicePdfRenderer::class);
        $totals = (fn (Invoice $i) => $this->totalsView($i))->call($renderer, $invoice);

        return view('invoices.pdf', [
            'invoice' => $invoice,
            'tenant' => $invoice->company,
            'qrDataUri' => null,
            'logoDataUri' => null,
            'totals' => $totals,
        ])->render();
    }

    public function test_original_fully_credited_shows_status_and_credit_note_code(): void
    {
        $original = $this->invoice();
        // Create the credit note FIRST (its creation may recompute the original's
        // credited_total), then set the caches LAST so isFullyCredited() holds.
        $this->invoice(['code' => 10, 'invcode' => 'ΠΙΣ10', 'credited_invoice_id' => $original->id]);
        $original->forceFill(['gross_total' => 100, 'credited_total' => 100])->save();

        $html = $this->renderHtml($original);

        $this->assertStringContainsString('Ακυρώθηκε με πιστωτικό', $html);     // the reversal badge
        $this->assertStringContainsString('Ακυρώθηκε / πιστώθηκε με', $html);
        $this->assertStringContainsString('ΠΙΣ10', $html);                      // the credit note code
        $this->assertStringContainsString('παραμένει VALID στην ΑΑΔΕ', $html);  // the helper note
    }

    public function test_credit_note_shows_the_invoice_it_reverses(): void
    {
        $original = $this->invoice();
        $credit = $this->invoice(['code' => 10, 'invcode' => 'ΠΙΣ10', 'credited_invoice_id' => $original->id]);

        $html = $this->renderHtml($credit->fresh());

        $this->assertStringContainsString('αντιστρέφει το παραστατικό', $html);
        $this->assertStringContainsString('TPY6656', $html);
    }

    public function test_no_related_section_for_a_plain_invoice(): void
    {
        $html = $this->renderHtml($this->invoice()->fresh());

        // Assert on rendered CONTENT (the «Σχετικά παραστατικά» heading text also
        // appears nowhere now; the helper/link strings only exist when rendered).
        $this->assertStringNotContainsString('Ακυρώθηκε / πιστώθηκε με', $html);
        $this->assertStringNotContainsString('αντιστρέφει το παραστατικό', $html);
        $this->assertStringNotContainsString('class="related"', $html);
    }

    public function test_renderer_produces_pdf(): void
    {
        $original = $this->invoice();
        $original->forceFill(['gross_total' => 100, 'credited_total' => 100])->save();
        $this->invoice(['code' => 10, 'invcode' => 'ΠΙΣ10', 'credited_invoice_id' => $original->id]);

        $this->assertStringStartsWith('%PDF', app(InvoicePdfRenderer::class)->render($original->fresh()));
    }
}
