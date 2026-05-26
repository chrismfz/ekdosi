<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\InvoiceLine;
use App\Models\InvoiceType;
use App\Services\InvoiceVatBreakdown;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Locks in the port of CALCULATE_VAT_FOR_INVOICE
 * (legacy/ekdosi-schema.sql:490). Critical: the legacy stored proc
 * rounds at the GROUP BY aggregate level (one round() per VAT rate),
 * NEVER per-line. Mis-rounding loses €0.01 vs the legacy filed
 * values and the parallel-run golden test would fail.
 */
class InvoiceVatBreakdownTest extends TestCase
{
    use RefreshDatabase;

    private Company $tenant;

    private Customer $customer;

    private InvoiceType $invoiceType;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Company::create([
            'name' => 'Test',
            'slug' => 'vat-'.uniqid(),
            'country_code' => 'GR',
            'einvoice_provider' => 'gr-mydata',
            'mydata_mode' => 'off',
        ]);

        $this->customer = Customer::create([
            'company_id' => $this->tenant->id,
            'name' => 'Customer',
        ]);

        $this->invoiceType = InvoiceType::create([
            'company_id' => $this->tenant->id,
            'code' => 'APY',
            'name' => 'ΑΠΥ',
            'invcount' => 1,
            'mydata_type' => '2.1',
        ]);
    }

    public function test_single_rate_single_line(): void
    {
        $inv = $this->makeInvoice(0);
        $this->addLine($inv, vat: 24, net: 100, gross: 124);

        $b = InvoiceVatBreakdown::for($inv->fresh(['lines']));

        $this->assertCount(1, $b->rows);
        $this->assertSame(24.0, $b->rows[0]['rate']);
        $this->assertSame(100.00, $b->totalNet());
        $this->assertSame(24.00, $b->totalVat());
        $this->assertSame(124.00, $b->totalGross());
    }

    public function test_multiple_rates_grouped_and_sorted(): void
    {
        $inv = $this->makeInvoice(0);
        // Three lines at three rates; result must be grouped and
        // sorted by rate ascending.
        $this->addLine($inv, vat: 24, net: 100, gross: 124);
        $this->addLine($inv, vat: 13, net: 50, gross: 56.50);
        $this->addLine($inv, vat: 24, net: 50, gross: 62);   // second 24% line
        $this->addLine($inv, vat: 6, net: 10, gross: 10.60);

        $b = InvoiceVatBreakdown::for($inv->fresh(['lines']));

        $this->assertCount(3, $b->rows);
        $this->assertSame(6.0, $b->rows[0]['rate']);
        $this->assertSame(13.0, $b->rows[1]['rate']);
        $this->assertSame(24.0, $b->rows[2]['rate']);

        // 24% rows: net = 100 + 50 = 150, vat = 24 + 12 = 36
        $this->assertSame(150.00, $b->rows[2]['net']);
        $this->assertSame(36.00, $b->rows[2]['vat']);
        // 13% row: net = 50, vat = 6.50
        $this->assertSame(50.00, $b->rows[1]['net']);
        $this->assertSame(6.50, $b->rows[1]['vat']);
        // 6% row: net = 10, vat = 0.60
        $this->assertSame(10.00, $b->rows[0]['net']);
        $this->assertSame(0.60, $b->rows[0]['vat']);
    }

    public function test_header_discount_applied_at_aggregate_not_per_line(): void
    {
        // The critical case: header discount of 10% on a multi-rate
        // invoice. Legacy applies the discount to the SUMMED vat-per-
        // rate, not to each line individually. Per-line rounding could
        // produce a different total than the legacy one.
        $inv = $this->makeInvoice(headerDiscount: 10);  // 10%
        $this->addLine($inv, vat: 24, net: 100.55, gross: 124.68);
        $this->addLine($inv, vat: 24, net: 99.45,  gross: 123.32);
        $this->addLine($inv, vat: 13, net: 33.33,  gross: 37.66);

        $b = InvoiceVatBreakdown::for($inv->fresh(['lines']));

        // 24% bucket: pre-discount net 200.00, vat 48.00
        // After 10% discount: net = 180.00, vat = 43.20
        $row24 = collect($b->rows)->firstWhere('rate', 24.0);
        $this->assertSame(180.00, $row24['net']);
        $this->assertSame(43.20, $row24['vat']);

        // 13% bucket: pre-discount net 33.33, vat 4.33
        // After 10% discount: net = 30.00 (29.997 rounded), vat = 3.90 (3.897 rounded)
        $row13 = collect($b->rows)->firstWhere('rate', 13.0);
        $this->assertSame(30.00, $row13['net']);
        $this->assertSame(3.90, $row13['vat']);
    }

    public function test_vat_at_rate_lookup_returns_zero_for_missing_rate(): void
    {
        $inv = $this->makeInvoice(0);
        $this->addLine($inv, vat: 24, net: 100, gross: 124);

        $b = InvoiceVatBreakdown::for($inv->fresh(['lines']));

        $this->assertSame(24.00, $b->vatAtRate(24));
        $this->assertSame(0.0, $b->vatAtRate(13));  // not present
    }

    private function makeInvoice(float $headerDiscount): Invoice
    {
        return Invoice::create([
            'company_id' => $this->tenant->id,
            'invcode' => 'APY'.uniqid(),
            'code' => 1,
            'invoice_type_id' => $this->invoiceType->id,
            'customer_id' => $this->customer->id,
            'issued_at' => now(),
            'header_discount_percent' => $headerDiscount,
        ]);
    }

    private function addLine(Invoice $inv, float $vat, float $net, float $gross): InvoiceLine
    {
        return InvoiceLine::create([
            'company_id' => $this->tenant->id,
            'invoice_id' => $inv->id,
            'qty' => 1,
            'vat_percent' => $vat,
            'net_price' => $net,
            'gross_price' => $gross,
        ]);
    }
}
