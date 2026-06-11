<?php

namespace Tests\Feature\Invoice;

use App\Models\Company;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\InvoiceLine;
use App\Models\InvoiceType;
use App\Models\Quote;
use App\Models\QuoteLine;
use App\Services\InvoicePdfRenderer;
use App\Services\QuoteNumberer;
use App\Services\QuotePdfRenderer;
use App\Services\RecomputeInvoiceTotals;
use App\Services\QuoteTotals;
use App\Support\Pdf\PdfLabels;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Per-document PDF language (GR/EN/bilingual). The labels are localized via
 * PdfLabels; the choice is per-invoice, defaulting from the recipient country
 * (GR → Greek, foreign → bilingual). Only labels change — never amounts/content.
 */
class BilingualPdfTest extends TestCase
{
    use RefreshDatabase;

    private Company $tenant;

    private InvoiceType $type;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = Company::create([
            'name' => 'Bi', 'slug' => 'bi-'.uniqid(), 'country_code' => 'GR',
            'einvoice_provider' => 'gr-mydata', 'mydata_mode' => 'off',
        ]);
        $this->type = InvoiceType::create([
            'company_id' => $this->tenant->id, 'code' => 'TPY', 'name' => 'ΤΠΥ', 'invcount' => 1,
        ]);
    }

    private function renderHtml(Invoice $invoice, string $lang): string
    {
        $invoice->loadMissing(['lines', 'invoiceType', 'customer', 'company']);
        $renderer = app(InvoicePdfRenderer::class);
        $totals = (fn (Invoice $i) => $this->totalsView($i))->call($renderer, $invoice);

        return view('invoices.pdf', [
            'invoice' => $invoice, 'tenant' => $invoice->company,
            'qrDataUri' => null, 'logoDataUri' => null, 'totals' => $totals,
            'L' => PdfLabels::for($lang),
        ])->render();
    }

    private function invoice(array $overrides = []): Invoice
    {
        return Invoice::create(array_merge([
            'company_id' => $this->tenant->id, 'invoice_type_id' => $this->type->id,
            'code' => 1, 'invcode' => 'TPY1', 'issued_at' => now(), 'local_status' => 'active',
        ], $overrides));
    }

    #[Test]
    public function resolve_language_defaults_from_country_and_honours_an_explicit_choice(): void
    {
        $this->assertSame('el', PdfLabels::resolveLanguage(null, 'GR'));
        $this->assertSame('el', PdfLabels::resolveLanguage(null, null));
        $this->assertSame('both', PdfLabels::resolveLanguage(null, 'DE'));   // foreign → bilingual
        $this->assertSame('en', PdfLabels::resolveLanguage('en', 'GR'));     // explicit wins
        $this->assertSame('both', PdfLabels::resolveLanguage('both', 'GR'));
    }

    #[Test]
    public function the_dictionary_renders_each_mode(): void
    {
        $this->assertSame('Περιγραφή', PdfLabels::for('el')->get('description'));
        $this->assertSame('Description', PdfLabels::for('en')->get('description'));
        $this->assertSame('Περιγραφή / Description', PdfLabels::for('both')->get('description'));
    }

    #[Test]
    public function the_invoice_pdf_renders_english_labels(): void
    {
        $html = $this->renderHtml($this->invoice()->fresh(), 'en');

        $this->assertStringContainsString('DESCRIPTION', $html);     // header (@gup-uppercased)
        $this->assertStringContainsString('Net value', $html);       // totals label (not uppercased)
        $this->assertStringNotContainsString('Καθαρή αξία', $html);  // no Greek totals label in EN mode
    }

    #[Test]
    public function the_invoice_pdf_renders_bilingual_labels(): void
    {
        $html = $this->renderHtml($this->invoice()->fresh(), 'both');

        // @gup uppercases the header, so assert on the totals label (not uppercased).
        $this->assertStringContainsString('Καθαρή αξία / Net value', $html);
    }

    #[Test]
    public function a_foreign_invoice_defaults_to_bilingual_end_to_end(): void
    {
        // No explicit language + a non-GR recipient → the renderer picks bilingual.
        $invoice = $this->invoice(['country' => 'DE'])->fresh();

        $this->assertStringStartsWith('%PDF', app(InvoicePdfRenderer::class)->render($invoice));
        $this->assertSame('both', PdfLabels::resolveLanguage($invoice->language, $invoice->country));
    }

    #[Test]
    public function the_pdf_payable_line_reflects_withholding(): void
    {
        $inv = $this->invoice(['withhold_rate' => 20, 'withhold_category' => 1]); // 20% withholding
        InvoiceLine::create([
            'company_id' => $this->tenant->id, 'invoice_id' => $inv->id,
            'qty' => 1, 'price_per_item' => 1000, 'vat_percent' => 24, // net 1000, gross 1240
        ]);
        app(RecomputeInvoiceTotals::class)($inv);

        $html = $this->renderHtml($inv->fresh(), 'el');

        $this->assertStringContainsString('Παρακράτηση', $html);           // the withholding line
        $this->assertStringContainsString('200,00', $html);                // withheld amount
        $this->assertStringContainsString('Πληρωτέο', $html);              // the collectible row
        $this->assertStringContainsString('1.040,00', $html);              // 1240 − 200
    }

    #[Test]
    public function the_quote_pdf_renders_localized_labels(): void
    {
        $customer = Customer::create(['company_id' => $this->tenant->id, 'name' => 'Acme GmbH']);
        $quote = Quote::create([
            'company_id' => $this->tenant->id, 'customer_id' => $customer->id,
            'code' => app(QuoteNumberer::class)->allocate($this->tenant),
            'subject' => 'Offer', 'company_name' => 'Acme GmbH', 'issued_at' => now(),
        ]);
        QuoteLine::create(['quote_id' => $quote->id, 'product_descr' => 'Service', 'qty' => 1, 'price_per_item' => 100, 'vat_percent' => 24]);
        $quote = app(QuoteTotals::class)($quote)->fresh();

        $renderer = app(QuotePdfRenderer::class);
        $totals = (fn (Quote $q) => $this->totalsView($q))->call($renderer, $quote->loadMissing(['lines', 'customer', 'company']));
        $view = fn (string $lang) => view('quotes.pdf', [
            'quote' => $quote, 'tenant' => $quote->company, 'logoDataUri' => null,
            'totals' => $totals, 'L' => PdfLabels::for($lang),
        ])->render();

        $en = $view('en');
        $this->assertStringContainsString('Net value', $en);          // totals label (not uppercased)
        $this->assertStringNotContainsString('Καθαρή αξία', $en);     // Greek totals label gone

        $this->assertStringContainsString('Καθαρή αξία / Net value', $view('both'));
    }
}
