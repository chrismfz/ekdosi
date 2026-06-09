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

    public function test_history_section_renders_in_html(): void
    {
        $invoice = $this->invoiceWithHistory();
        $invoice->loadMissing(['lines', 'invoiceType', 'customer', 'company']);

        // Real totals shape via the renderer's own (private) builder, so the test
        // isn't coupled to the totals array's exact keys.
        $renderer = app(InvoicePdfRenderer::class);
        $totals = (fn (Invoice $i) => $this->totalsView($i))->call($renderer, $invoice);

        $html = view('invoices.pdf', [
            'invoice' => $invoice,
            'tenant' => $invoice->company,
            'qrDataUri' => null,
            'logoDataUri' => null,
            'totals' => $totals,
            'activities' => $invoice->activitiesAsSubject()->with('causer')->oldest()->get(),
        ])->render();

        $this->assertStringContainsString('Ιστορικό', $html);
        $this->assertStringContainsString('Τροποποίηση', $html);     // the updated event
        $this->assertStringContainsString('local_status', $html);    // the change line
    }
}
