<?php

namespace Tests\Feature\Invoice;

use App\Models\Company;
use App\Models\Invoice;
use App\Models\InvoiceType;
use App\Services\InvoicePdfRenderer;
use App\Support\Pdf\PdfLabels;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * DOC-5: the myDATA ΜΑΡΚ is printed whenever the invoice carries one, decoupled
 * from the QR image. An ETL-imported legacy invoice is VALID with a mydata_mark
 * but NO mydata_url (→ no QR) — it must still show its ΜΑΡΚ (previously it came
 * out with neither ΜΑΡΚ nor QR nor a draft banner).
 */
class InvoicePdfMarkTest extends TestCase
{
    use RefreshDatabase;

    private Company $tenant;

    private InvoiceType $type;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = Company::create([
            'name' => 'Mark', 'slug' => 'mark-'.uniqid(),
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
            'invcode' => 'TPY100',
            'issued_at' => now(),
            'local_status' => 'active',
        ], $overrides));
    }

    private function renderHtml(Invoice $invoice, ?string $qrDataUri): string
    {
        $invoice->loadMissing(['lines', 'invoiceType', 'customer', 'company']);
        $renderer = app(InvoicePdfRenderer::class);
        $totals = (fn (Invoice $i) => $this->totalsView($i))->call($renderer, $invoice);

        return view('invoices.pdf', [
            'invoice' => $invoice,
            'tenant' => $invoice->company,
            'qrDataUri' => $qrDataUri,
            'logoDataUri' => null,
            'totals' => $totals,
            'L' => PdfLabels::for(
                PdfLabels::resolveLanguage($invoice->language, $invoice->country)
            ),
        ])->render();
    }

    public function test_valid_invoice_with_mark_but_no_qr_still_prints_the_mark(): void
    {
        // ETL-imported legacy invoice: VALID + ΜΑΡΚ, but no mydata_url → no QR.
        $inv = $this->invoice();
        $inv->forceFill(['mydata_state' => 'VALID', 'mydata_mark' => '400001234567890'])->save();

        $html = $this->renderHtml($inv, qrDataUri: null);

        $this->assertStringContainsString('400001234567890', $html);   // the ΜΑΡΚ value
        $this->assertStringContainsString('ΜΑΡΚ', $html);              // the standalone label
    }

    public function test_mark_shows_without_the_label_when_the_qr_provides_context(): void
    {
        // With a QR, the mark renders raw (the «myDATA» QR label gives context) —
        // no redundant «ΜΑΡΚ:» prefix in the header block.
        $inv = $this->invoice();
        $inv->forceFill(['mydata_state' => 'VALID', 'mydata_mark' => '400009999999999', 'mydata_url' => 'https://x/y'])->save();

        $html = $this->renderHtml($inv, qrDataUri: 'data:image/png;base64,AAAA');

        $this->assertStringContainsString('400009999999999', $html);
        // The header mark line must NOT carry the standalone «ΜΑΡΚ: » prefix here.
        $this->assertStringNotContainsString('ΜΑΡΚ: 400009999999999', $html);
    }

    public function test_no_mark_no_qr_prints_neither(): void
    {
        $inv = $this->invoice();   // draft-ish state, no mark

        $html = $this->renderHtml($inv, qrDataUri: null);

        // The `.qr-block` CSS rule is always present; assert the DIV isn't rendered.
        $this->assertStringNotContainsString('<div class="qr-block">', $html);
    }
}
