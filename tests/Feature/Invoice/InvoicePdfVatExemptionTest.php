<?php

namespace Tests\Feature\Invoice;

use App\Models\Company;
use App\Models\Invoice;
use App\Models\InvoiceLine;
use App\Models\InvoiceType;
use App\Models\VatCategory;
use App\Services\InvoicePdfRenderer;
use App\Support\Pdf\PdfLabels;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * DOC-1 (AUDIT): a 0% invoice PDF must print the VAT-exemption legal citation
 * (ΕΛΠ ν.4308/2014 αρ.9 — e.g. «Χωρίς ΦΠΑ - άρθρο 45» for intra-community),
 * sourced from the tenant's 0%-rate VatCategory exactly like the myDATA
 * submitter — but non-throwing: an unconfigured tenant still renders.
 */
class InvoicePdfVatExemptionTest extends TestCase
{
    use RefreshDatabase;

    private Company $tenant;

    private InvoiceType $type;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = Company::create([
            'name' => 'Exempt', 'slug' => 'ex-'.uniqid(),
            'country_code' => 'GR', 'einvoice_provider' => 'gr-mydata', 'mydata_mode' => 'off',
        ]);
        $this->type = InvoiceType::create([
            'company_id' => $this->tenant->id, 'code' => 'TPY', 'name' => 'ΤΠΥ', 'invcount' => 1,
        ]);
    }

    private function invoice(): Invoice
    {
        return Invoice::create([
            'company_id' => $this->tenant->id,
            'invoice_type_id' => $this->type->id,
            'code' => 1,
            'invcode' => 'TPY77',
            'issued_at' => now(),
            'local_status' => 'active',
        ]);
    }

    private function line(Invoice $inv, float $vatPercent): void
    {
        InvoiceLine::create([
            'company_id' => $this->tenant->id,
            'invoice_id' => $inv->id,
            'qty' => 1,
            'vat_percent' => $vatPercent,
            'price_per_item' => 100,
        ]);
    }

    private function renderHtml(Invoice $invoice): string
    {
        $invoice->loadMissing(['lines', 'invoiceType', 'customer', 'company']);
        $renderer = app(InvoicePdfRenderer::class);
        $totals = (fn (Invoice $i) => $this->totalsView($i))->call($renderer, $invoice);

        return view('invoices.pdf', [
            'invoice' => $invoice,
            'tenant' => $invoice->company,
            'qrDataUri' => null,
            'logoDataUri' => null,
            'totals' => $totals,
            'L' => PdfLabels::for(
                PdfLabels::resolveLanguage($invoice->language, $invoice->country)
            ),
        ])->render();
    }

    public function test_zero_vat_invoice_prints_the_exemption_citation(): void
    {
        VatCategory::create([
            'company_id' => $this->tenant->id,
            'description' => 'Ενδοκοινοτική', 'rate' => 0, 'vat_exemption_category' => 16,
        ]);
        $inv = $this->invoice();
        $this->line($inv, 0);

        $html = $this->renderHtml($inv->fresh());

        $this->assertStringContainsString('Απαλλαγή ΦΠΑ', $html);
        $this->assertStringContainsString('άρθρο 45 του Κώδικα ΦΠΑ', $html);
        $this->assertStringContainsString('§8.3-16', $html);
    }

    public function test_per_line_reason_is_cited_even_with_multiple_zero_categories(): void
    {
        // MYD-007: with SEVERAL 0% categories (now the seeded default), the printed
        // citation comes from the LINE's own reason — the tenant-wide fallback would
        // be ambiguous. Here the line was filed under reason 4 (άρθρο 18), not 16.
        VatCategory::create([
            'company_id' => $this->tenant->id, 'description' => '0% RC', 'rate' => 0, 'vat_exemption_category' => 16,
        ]);
        VatCategory::create([
            'company_id' => $this->tenant->id, 'description' => '0% ενδοκοιν. υπ.', 'rate' => 0, 'vat_exemption_category' => 4,
        ]);
        $inv = $this->invoice();
        InvoiceLine::create([
            'company_id' => $this->tenant->id, 'invoice_id' => $inv->id,
            'qty' => 1, 'vat_percent' => 0, 'price_per_item' => 100, 'vat_exemption_category' => 4,
        ]);

        $html = $this->renderHtml($inv->fresh());

        $this->assertStringContainsString('§8.3-4', $html);          // the LINE's reason
        $this->assertStringContainsString('άρθρο 18 του Κώδικα ΦΠΑ', $html);
        $this->assertStringNotContainsString('§8.3-16', $html);      // NOT the other category
    }

    public function test_unconfigured_exemption_renders_without_the_note(): void
    {
        // No 0%-rate VatCategory with a reason — the PDF must still render
        // (draft/preview), just without the citation (preflight is the guard).
        $inv = $this->invoice();
        $this->line($inv, 0);

        $html = $this->renderHtml($inv->fresh());

        $this->assertStringNotContainsString('Απαλλαγή ΦΠΑ', $html);
    }

    public function test_taxed_invoice_prints_no_exemption_note(): void
    {
        VatCategory::create([
            'company_id' => $this->tenant->id,
            'description' => 'Ενδοκοινοτική', 'rate' => 0, 'vat_exemption_category' => 16,
        ]);
        $inv = $this->invoice();
        $this->line($inv, 24);

        $html = $this->renderHtml($inv->fresh());

        $this->assertStringNotContainsString('Απαλλαγή ΦΠΑ', $html);
    }
}
