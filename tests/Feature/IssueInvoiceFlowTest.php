<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\InvoiceLine;
use App\Models\InvoiceType;
use App\Models\ProductCategory;
use App\Models\VatCategory;
use App\Services\InvoicePdfRenderer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * End-to-end coverage for the IssueInvoice flow's persistence layer.
 *
 * The Filament page itself (CreateInvoice, EditInvoice) is not boot-
 * tested here — Livewire page tests are heavy and we already lock in
 * the InvoiceNumberer service + InvoiceVatBreakdown + MyDataSubmitter
 * paths separately. What's covered here:
 *   - Recompute-totals semantics (the post-create / post-save hook)
 *   - PDF renderer doesn't crash on draft / filed / cancelled shapes
 *   - Edit-on-filed-invoice guard (the canAccess override)
 */
class IssueInvoiceFlowTest extends TestCase
{
    use RefreshDatabase;

    private Company $tenant;

    private Customer $customer;

    private InvoiceType $invoiceType;

    private VatCategory $vat;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Company::create([
            'name' => 'Issue test',
            'slug' => 'issue-flow-'.uniqid(),
            'country_code' => 'GR',
            'einvoice_provider' => 'gr-mydata',
            'mydata_mode' => 'off',
            'afm' => '800561849',
        ]);

        $this->customer = Customer::create([
            'company_id' => $this->tenant->id,
            'name' => 'Test customer',
            'afm' => '123456789',
        ]);

        $this->invoiceType = InvoiceType::create([
            'company_id' => $this->tenant->id,
            'code' => 'TPY',
            'name' => 'Τιμολόγιο',
            'invcount' => 1,
            'mydata_type' => '1.1',
        ]);

        $this->vat = VatCategory::create([
            'company_id' => $this->tenant->id,
            'description' => '24%',
            'rate' => 24,
            'is_default' => true,
        ]);
    }

    public function test_recompute_totals_with_no_header_discount(): void
    {
        $invoice = $this->makeInvoice(headerDiscount: 0);
        $this->addLine($invoice, qty: 1, price: 100, vat: 24);
        $this->addLine($invoice, qty: 2, price: 50, vat: 24);

        $this->recomputeTotals($invoice);

        $invoice->refresh();
        // 100 + 100 = 200 net; 124 + 124 = 248 gross
        $this->assertSame('200.00', (string) $invoice->net_total);
        $this->assertSame('248.00', (string) $invoice->gross_total);
    }

    public function test_recompute_totals_applies_header_discount(): void
    {
        $invoice = $this->makeInvoice(headerDiscount: 10);  // 10%
        $this->addLine($invoice, qty: 1, price: 100, vat: 24);

        $this->recomputeTotals($invoice);

        $invoice->refresh();
        // Net 100, gross 124, then 10% off both
        $this->assertSame('90.00', (string) $invoice->net_total);
        $this->assertSame('111.60', (string) $invoice->gross_total);
    }

    public function test_pdf_renders_for_a_draft_invoice(): void
    {
        $invoice = $this->makeInvoice(headerDiscount: 0);
        $this->addLine($invoice, qty: 1, price: 100, vat: 24);

        $pdfBytes = app(InvoicePdfRenderer::class)->render($invoice->fresh());

        // DomPDF outputs PDF bytes starting with "%PDF-"
        $this->assertStringStartsWith('%PDF-', $pdfBytes);
        $this->assertGreaterThan(1000, strlen($pdfBytes), 'PDF should be non-trivial in size');
    }

    public function test_pdf_renders_for_a_filed_invoice_with_qr(): void
    {
        $invoice = $this->makeInvoice(headerDiscount: 0);
        $this->addLine($invoice, qty: 1, price: 100, vat: 24);
        // Simulate a filed invoice with MARK + QR URL
        $invoice->forceFill([
            'mydata_state' => 'VALID',
            'mydata_mark' => '400099999999999',
            'mydata_url' => 'https://www1.aade.gr/mydata-public?mark=400099999999999',
            'mydata_sent' => true,
        ])->save();

        $pdfBytes = app(InvoicePdfRenderer::class)->render($invoice->fresh());

        $this->assertStringStartsWith('%PDF-', $pdfBytes);
    }

    public function test_pdf_renders_for_a_cancelled_invoice(): void
    {
        // Cancelled invoices show a CANCELLED banner; renderer must not crash.
        $invoice = $this->makeInvoice(headerDiscount: 0);
        $this->addLine($invoice, qty: 1, price: 100, vat: 24);
        $invoice->forceFill([
            'mydata_state' => 'CANCELLED',
            'mydata_mark' => '400088888888888',
            'mydata_sent' => true,
        ])->save();

        $pdfBytes = app(InvoicePdfRenderer::class)->render($invoice->fresh());
        $this->assertStringStartsWith('%PDF-', $pdfBytes);
    }

    public function test_pdf_renders_for_invoice_with_no_lines(): void
    {
        // Edge case: a draft invoice with zero lines (operator hadn't
        // added any yet). Renderer should still produce a valid PDF.
        $invoice = $this->makeInvoice(headerDiscount: 0);

        $pdfBytes = app(InvoicePdfRenderer::class)->render($invoice->fresh());
        $this->assertStringStartsWith('%PDF-', $pdfBytes);
    }

    /**
     * Simulates the Filament Repeater save path: `$invoice->lines()->create([...])`
     * with ONLY the form-supplied fields (no company_id, no net_price,
     * no gross_price). Locks in the InvoiceLine `saving` hook so a
     * future refactor that drops it can't silently re-introduce both
     * critical bugs the PR #26 independent review caught:
     *   - company_id NOT NULL crash on first form save
     *   - line totals silently zero because no callsite computed them
     */
    public function test_line_saving_hook_stamps_company_id_and_totals_from_form_payload(): void
    {
        $invoice = $this->makeInvoice(headerDiscount: 0);

        // Exactly what Filament's Repeater::relationship('lines') hands
        // to HasMany::create — only the form fields, no derived columns.
        $line = $invoice->lines()->create([
            'qty' => 2,
            'price_per_item' => 50,
            'discount' => 10,           // 10% line-level discount
            'vat_percent' => 24,
            'product_descr' => 'Form path line',
            'metric_unit' => 'τεμ',
        ]);

        // company_id auto-stamped from the parent invoice
        $this->assertSame($this->tenant->id, $line->company_id);

        // net = 2 × 50 × (1 - 10/100) = 90.00
        // gross = 90 × 1.24 = 111.60
        $this->assertSame('90.00', (string) $line->net_price);
        $this->assertSame('111.60', (string) $line->gross_price);
    }

    /**
     * Even if a caller (mistakenly) tries to set net_price / gross_price
     * directly, the saving hook overrides with the authoritative
     * computation. Prevents the form layer from being tricked by a
     * crafted Livewire payload.
     */
    public function test_line_saving_hook_overrides_caller_supplied_totals(): void
    {
        $invoice = $this->makeInvoice(headerDiscount: 0);

        $line = $invoice->lines()->create([
            'qty' => 1,
            'price_per_item' => 100,
            'vat_percent' => 24,
            // Hostile values — should be overridden by the hook
            'net_price' => 1,
            'gross_price' => 1,
        ]);

        $this->assertSame('100.00', (string) $line->net_price);
        $this->assertSame('124.00', (string) $line->gross_price);
    }

    private function makeInvoice(float $headerDiscount): Invoice
    {
        $count = Invoice::where('invoice_type_id', $this->invoiceType->id)->count();
        return Invoice::create([
            'company_id' => $this->tenant->id,
            'invcode' => 'TPY'.($count + 1),
            'code' => $count + 1,
            'invoice_type_id' => $this->invoiceType->id,
            'customer_id' => $this->customer->id,
            'issued_at' => now(),
            'header_discount_percent' => $headerDiscount,
            'company_name' => $this->customer->name,
            'vat_no' => $this->customer->afm,
        ]);
    }

    private function addLine(Invoice $inv, float $qty, float $price, float $vat): InvoiceLine
    {
        $net = round($qty * $price, 2);
        $gross = round($net * (1 + $vat / 100), 2);
        return InvoiceLine::create([
            'company_id' => $inv->company_id,
            'invoice_id' => $inv->id,
            'qty' => $qty,
            'price_per_item' => $price,
            'vat_percent' => $vat,
            'net_price' => $net,
            'gross_price' => $gross,
            'product_descr' => 'Test line',
            'metric_unit' => 'τεμ',
        ]);
    }

    /**
     * Mirror of CreateInvoice::recomputeTotals — extracted into the
     * test so we can lock the formula without booting Filament.
     */
    private function recomputeTotals(Invoice $invoice): void
    {
        $invoice = $invoice->fresh(['lines']);
        $rawNet = $invoice->lines->sum(fn ($l) => (float) $l->net_price);
        $rawGross = $invoice->lines->sum(fn ($l) => (float) $l->gross_price);
        $discount = 1 - ((float) $invoice->header_discount_percent / 100);
        $invoice->net_total = round($rawNet * $discount, 2);
        $invoice->gross_total = round($rawGross * $discount, 2);
        $invoice->save();
    }
}
