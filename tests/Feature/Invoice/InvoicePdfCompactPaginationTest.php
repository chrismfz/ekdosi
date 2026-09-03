<?php

namespace Tests\Feature\Invoice;

use App\Models\BankAccount;
use App\Models\Company;
use App\Models\Invoice;
use App\Models\InvoiceLine;
use App\Models\InvoiceType;
use App\Models\MyDataMark;
use App\Models\PaymentMethod;
use App\Services\InvoicePdfRenderer;
use App\Services\RecomputeInvoiceTotals;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * PDF-COMPACT: the invoice PDF must fit a typical (1–few line) invoice on ONE A4
 * page. The pre-compaction PR-27 layout pushed even a SINGLE-line, provider-filed
 * invoice onto a 2nd page (roomy margins/fonts/gaps), which is exactly the
 * regression this locks. Also verifies the «Σελίδα X από Y» pager is drawn via the
 * DomPDF text-callback (counter(pages) resolves to 0 in a fixed footer on DomPDF 3.x).
 */
class InvoicePdfCompactPaginationTest extends TestCase
{
    use RefreshDatabase;

    private string $logoPath = '';

    protected function tearDown(): void
    {
        if ($this->logoPath !== '') {
            Storage::disk('public')->delete($this->logoPath);
        }
        parent::tearDown();
    }

    /** Count PDF pages from raw bytes: each page is a `/Type /Page` object (the page
     *  TREE is `/Type /Pages` — excluded by the negative lookahead). */
    private function pageCount(string $pdf): int
    {
        return preg_match_all('#/Type\s*/Page(?![s])#', $pdf);
    }

    /** A faithful worst-case single-line invoice: provider evidence + QR + full
     *  customer block + two payment accounts + a wordmark logo — the exact shape
     *  that used to spill onto a 2nd page. */
    private function heavyInvoice(int $lines = 1): Invoice
    {
        // Wordmark-style logo so the header height is realistic (max 14mm tall).
        $img = imagecreatetruecolor(320, 84);
        imagefill($img, 0, 0, imagecolorallocate($img, 255, 255, 255));
        imagefilledrectangle($img, 0, 24, 60, 60, imagecolorallocate($img, 37, 99, 235));
        ob_start();
        imagepng($img);
        $png = (string) ob_get_clean();
        $this->logoPath = 'logos/compact-test-'.uniqid().'.png';
        Storage::disk('public')->put($this->logoPath, $png);

        $tenant = Company::create([
            'name' => 'MyIP Networks OE', 'slug' => 'compact-'.uniqid(),
            'country_code' => 'GR', 'einvoice_provider' => 'gr-provider',
            'einvoice_provider_key' => 'invosign', 'einvoice_provider_mode' => 'production',
            'afm' => '800561849', 'tax_office' => 'ΔΟΥ Ξάνθης',
            'address' => 'Κανάρη 5', 'city' => 'Ξάνθη', 'postcode' => '67100',
            'gemi' => '129451646000', 'kad_primary' => 'Υπηρεσίες Διαδικτύου',
            'phone' => '215 215 4722', 'email' => 'support@myip.gr',
            'logo_path' => $this->logoPath,
        ]);

        $pm = PaymentMethod::create(['company_id' => $tenant->id, 'description' => 'Ηλεκτρονικά μέσα Πληρωμών', 'due_days' => 0]);
        BankAccount::create(['company_id' => $tenant->id, 'bank_name' => 'Eurobank', 'iban' => 'GR5202603140000380201332261', 'is_active' => true, 'show_on_invoices' => true]);
        BankAccount::create(['company_id' => $tenant->id, 'bank_name' => 'Πειραιώς', 'iban' => 'GR6401716600006666149271201', 'is_active' => true, 'show_on_invoices' => true]);

        $type = InvoiceType::create(['company_id' => $tenant->id, 'code' => 'TPY', 'name' => 'ΤΙΜΟΛΟΓΙΟ ΠΑΡΟΧΗΣ ΥΠΗΡΕΣΙΩΝ', 'invcount' => 6663, 'mydata_type' => '2.1']);

        $invoice = Invoice::create([
            'company_id' => $tenant->id, 'invoice_type_id' => $type->id, 'payment_method_id' => $pm->id,
            'code' => 6663, 'invcode' => 'TPY6663', 'issued_at' => now(), 'local_status' => 'active', 'country' => 'GR',
            'company_name' => 'ΠΟΤΑΠ ΠΙΕΡΙΑΣ', 'occupation' => 'ΟΡΓΑΝΙΣΜΟΣ ΤΟΥΡΙΣΤΙΚΗΣ ΑΝΑΠΤΥΞΗΣ & ΠΡΟΒΟΛΗΣ',
            'address1' => '28ΗΣ ΟΚΤΩΒΡΙΟΥ 40', 'city' => 'ΚΑΤΕΡΙΝΗ', 'postcode' => '60100', 'vat_no' => '997334698',
        ]);
        $invoice->forceFill([
            'mydata_state' => 'VALID', 'mydata_mark' => '400001970315180',
            'mydata_url' => 'https://demo.invosign.gr/viewinvoice.php?afm=EL800561849&file=MTY&gvsenc=20a0c63a31b7253e33baa753a369a07e',
        ])->save();

        for ($i = 0; $i < $lines; $i++) {
            InvoiceLine::create([
                'company_id' => $tenant->id, 'invoice_id' => $invoice->id,
                'product_descr' => 'Υπηρεσίες Web Hosting '.($i + 1), 'metric_unit' => 'τεμ',
                'qty' => 1, 'price_per_item' => 0.81, 'vat_percent' => 24, 'net_price' => 0.81, 'gross_price' => 1.00,
            ]);
        }

        MyDataMark::create([
            'company_id' => $tenant->id, 'invoice_id' => $invoice->id, 'mark' => '400001970315180',
            'mydata_action' => 'PROVIDER_INSERT', 'provider_key' => 'invosign',
            'authentication_code' => '4AFA33552F5DDFF3231696AD8A0D10A80ED2B77D',
            'uid' => '23FF98A4D0E5264A4FFCD49982CFA9BF893A6638',
            'invoice_url' => 'https://demo.invosign.gr/viewinvoice.php?q=x',
            'mark_date' => now()->toDateString(), 'mark_time' => now()->toTimeString(),
        ]);

        app(RecomputeInvoiceTotals::class)($invoice);

        return $invoice->fresh();
    }

    public function test_single_line_provider_invoice_fits_one_page(): void
    {
        $pdf = app(InvoicePdfRenderer::class)->render($this->heavyInvoice(1));

        $this->assertSame(1, $this->pageCount($pdf), 'a single-line invoice must fit on ONE A4 page');
    }

    public function test_pager_uses_a_dompdf_text_callback_not_the_broken_counter(): void
    {
        $html = app(InvoicePdfRenderer::class)->renderHtml($this->heavyInvoice(1));

        // The pager is drawn via page_text with DomPDF's own page-number tokens…
        $this->assertStringContainsString('page_text', $html);
        $this->assertStringContainsString('{PAGE_NUM}', $html);
        $this->assertStringContainsString('{PAGE_COUNT}', $html);
        // …and the broken fixed-footer counter is gone (the `.pager-total` span
        // + `content: counter(pages)` rule that rendered «από 0»).
        $this->assertStringNotContainsString('pager-total', $html);
        $this->assertStringNotContainsString('content: counter(pages)', $html);
    }

    public function test_a_long_invoice_still_paginates(): void
    {
        // Sanity: compaction didn't accidentally force everything onto one page —
        // a genuinely long invoice still flows to a 2nd page (where the pager total
        // becomes «από 2»).
        $pdf = app(InvoicePdfRenderer::class)->render($this->heavyInvoice(40));

        $this->assertGreaterThanOrEqual(2, $this->pageCount($pdf));
    }
}
