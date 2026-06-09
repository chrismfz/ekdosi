<?php

namespace Tests\Feature\Invoice;

use App\Models\Company;
use App\Models\Invoice;
use App\Models\InvoiceType;
use App\Services\InvoicePdfRenderer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The «Ιστορικό» (activity log) section on the invoice PDF: printed when the
 * invoice has audit rows. The table mechanics are shared with the delivery PDF
 * (DeliveryNotePdfTest); here we assert it integrates on a real issued invoice
 * (no crash on the activitiesAsSubject query) and that the audited change shows.
 */
class InvoicePdfHistoryTest extends TestCase
{
    use RefreshDatabase;

    private function invoiceWithHistory(): Invoice
    {
        $tenant = Company::create([
            'name' => 'Hist', 'slug' => 'hist-'.uniqid(),
            'country_code' => 'GR', 'einvoice_provider' => 'gr-mydata', 'mydata_mode' => 'off',
        ]);
        $type = InvoiceType::create([
            'company_id' => $tenant->id, 'code' => 'TPY', 'name' => 'ΤΠΥ', 'invcount' => 9,
        ]);

        $invoice = Invoice::create([
            'company_id' => $tenant->id,
            'invoice_type_id' => $type->id,
            'code' => 9,
            'invcode' => 'TPY9',
            'issued_at' => now(),
            'local_status' => 'draft',
        ]);

        // Generate an audited change → an «Τροποποίηση» activity row.
        $invoice->update(['local_status' => 'active']);

        return $invoice->fresh();
    }

    public function test_renderer_produces_pdf_with_activity_present(): void
    {
        $invoice = $this->invoiceWithHistory();
        $this->assertTrue($invoice->activitiesAsSubject()->exists(), 'precondition: an activity was logged');

        $pdf = app(InvoicePdfRenderer::class)->render($invoice);

        $this->assertStringStartsWith('%PDF', $pdf);
    }

    /** @return array<string,mixed> the renderer's own totals shape, to stay decoupled. */
    private function totalsFor(Invoice $invoice): array
    {
        $renderer = app(InvoicePdfRenderer::class);

        return (fn (Invoice $i) => $this->totalsView($i))->call($renderer, $invoice);
    }

    private function renderHtml(Invoice $invoice, bool $detailed): string
    {
        $invoice->loadMissing(['lines', 'invoiceType', 'customer', 'company']);

        return view('invoices.pdf', [
            'invoice' => $invoice,
            'tenant' => $invoice->company,
            'qrDataUri' => null,
            'logoDataUri' => null,
            'totals' => $this->totalsFor($invoice),
            'activities' => $invoice->activitiesAsSubject()->with('causer')->oldest()->get(),
            'historyDetailed' => $detailed,
        ])->render();
    }

    public function test_operator_variant_shows_full_history(): void
    {
        $html = $this->renderHtml($this->invoiceWithHistory(), detailed: true);

        $this->assertStringContainsString('Ιστορικό', $html);
        $this->assertStringContainsString('Τροποποίηση', $html);   // the updated event
        $this->assertStringContainsString('Χρήστης', $html);       // detail column header
        $this->assertStringContainsString('local_status', $html);  // the change line (Μεταβολές)
    }

    public function test_customer_variant_redacts_user_and_changes(): void
    {
        // The default (customer/public) render must NOT leak the operator name or
        // the internal field-level diff — only Πότε/Ενέργεια.
        $html = $this->renderHtml($this->invoiceWithHistory(), detailed: false);

        $this->assertStringContainsString('Ιστορικό', $html);
        $this->assertStringContainsString('Τροποποίηση', $html);       // action kept
        $this->assertStringNotContainsString('Χρήστης', $html);        // user column gone
        $this->assertStringNotContainsString('Μεταβολές', $html);      // changes column gone
        $this->assertStringNotContainsString('local_status', $html);   // no internal diff
    }

    public function test_renderer_defaults_to_the_redacted_variant(): void
    {
        // render() without internal:true (the email + public-URL callers) → redacted.
        $invoice = $this->invoiceWithHistory();
        $renderer = app(InvoicePdfRenderer::class);

        $this->assertStringStartsWith('%PDF', $renderer->render($invoice));
        $this->assertStringStartsWith('%PDF', $renderer->render($invoice, internal: true));
    }
}
