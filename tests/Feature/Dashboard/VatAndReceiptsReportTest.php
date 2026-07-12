<?php

namespace Tests\Feature\Dashboard;

use App\Models\Company;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\InvoiceLine;
use App\Models\InvoiceType;
use App\Models\Payment;
use App\Services\Dashboard\DashboardMetrics;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The two Reports-page money metrics: ΦΠΑ ανά συντελεστή × τρίμηνο
 * (VAT-return helper, netting credit notes + header discount) and
 * receipts-per-month (cash side, keyed on pay_date, net of refunds).
 * Pinned against sqlite — the widgets can't be eyeballed in this sandbox.
 */
class VatAndReceiptsReportTest extends TestCase
{
    use RefreshDatabase;

    private Company $tenant;

    private Company $other;

    private InvoiceType $type;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-05-15 12:00:00'));

        $this->tenant = $this->makeCompany('tenant');
        $this->other = $this->makeCompany('other');
        $this->type = InvoiceType::create([
            'company_id' => $this->tenant->id, 'name' => 'ΤΠΥ', 'code' => 'ΤΠΥ', 'invcount' => 1,
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function makeCompany(string $slug): Company
    {
        return Company::create([
            'name' => $slug, 'slug' => $slug.'-'.uniqid(),
            'country_code' => 'GR', 'einvoice_provider' => 'gr-mydata', 'mydata_mode' => 'off',
        ]);
    }

    /**
     * Each line: [rate, net]. The InvoiceLine `saving` hook derives
     * net_price/gross_price from qty × price_per_item × vat — so we feed
     * price_per_item = net (qty 1, no discount) and let it compute the gross.
     *
     * @param  list<array{rate: float, net: float}>  $lines
     */
    private function makeInvoice(string $issuedAt, array $lines, array $attrs = []): Invoice
    {
        $invoice = Invoice::create(array_merge([
            'company_id' => $this->tenant->id, 'invcode' => 'ΤΠΥ'.uniqid(), 'code' => 1,
            'invoice_type_id' => $this->type->id, 'issued_at' => $issuedAt,
            'net_total' => 0, 'gross_total' => 0, 'local_status' => 'active',
        ], $attrs));

        foreach ($lines as $l) {
            InvoiceLine::create([
                'company_id' => $this->tenant->id, 'invoice_id' => $invoice->id,
                'qty' => 1, 'price_per_item' => $l['net'], 'discount' => 0, 'vat_percent' => $l['rate'],
            ]);
        }

        return $invoice;
    }

    public function test_vat_by_rate_by_quarter_nets_credit_notes_and_applies_header_discount(): void
    {
        // Q1 sale: 24% (net 100 → gross 124) + 13% (net 200 → gross 226).
        $q1 = $this->makeInvoice('2026-02-10 10:00:00', [
            ['rate' => 24, 'net' => 100],
            ['rate' => 13, 'net' => 200],
        ]);

        // Q3 sale at 24% (net 50 → gross 62) with a 10% header discount → factor 0.9.
        $this->makeInvoice('2026-08-10 10:00:00', [
            ['rate' => 24, 'net' => 50],
        ], ['header_discount_percent' => 10]);

        // Q1 credit note (correlated) reducing the 24% bucket by net 40 (gross 49.6).
        $this->makeInvoice('2026-02-20 10:00:00', [
            ['rate' => 24, 'net' => 40],
        ], ['credited_invoice_id' => $q1->id]);

        // A different tenant's invoice must not bleed in.
        Invoice::create([
            'company_id' => $this->other->id, 'invcode' => 'X', 'code' => 1,
            'invoice_type_id' => $this->type->id, 'issued_at' => '2026-02-10 10:00:00',
            'net_total' => 0, 'gross_total' => 0, 'local_status' => 'active',
        ]);

        $r = (new DashboardMetrics($this->tenant))->vatByRateByQuarter(2026);

        // Q1, 24%: net 100−40 = 60 ; vat (24)−(9.6) = 14.4
        $this->assertEqualsWithDelta(60.0, $r['quarters'][1]['rates']['24.00']['net'], 0.001);
        $this->assertEqualsWithDelta(14.4, $r['quarters'][1]['rates']['24.00']['vat'], 0.001);
        // Q1, 13%: untouched by the credit note.
        $this->assertEqualsWithDelta(200.0, $r['quarters'][1]['rates']['13.00']['net'], 0.001);
        $this->assertEqualsWithDelta(26.0, $r['quarters'][1]['rates']['13.00']['vat'], 0.001);
        // Q3, 24% with 10% header discount: net 45 ; vat 10.8
        $this->assertEqualsWithDelta(45.0, $r['quarters'][3]['rates']['24.00']['net'], 0.001);
        $this->assertEqualsWithDelta(10.8, $r['quarters'][3]['rates']['24.00']['vat'], 0.001);
        // Empty quarters stay zeroed (dense).
        $this->assertEqualsWithDelta(0.0, $r['quarters'][2]['vat'], 0.001);
        $this->assertEqualsWithDelta(0.0, $r['quarters'][4]['vat'], 0.001);

        // Annual totals.
        $this->assertEqualsWithDelta(305.0, $r['totals']['net'], 0.001);  // 60+200+45
        $this->assertEqualsWithDelta(51.2, $r['totals']['vat'], 0.001);   // 14.4+26+10.8
        $this->assertEqualsWithDelta(105.0, $r['totals']['rates']['24.00']['net'], 0.001); // 60+45
        $this->assertEqualsWithDelta(25.2, $r['totals']['rates']['24.00']['vat'], 0.001);  // 14.4+10.8

        // Rates present, ascending.
        $this->assertEqualsWithDelta([13.0, 24.0], $r['rates'], 0.001);
    }

    public function test_receipts_by_month_uses_pay_date_and_nets_refunds(): void
    {
        $cust = Customer::create(['company_id' => $this->tenant->id, 'name' => 'Π']);
        $otherCust = Customer::create(['company_id' => $this->other->id, 'name' => 'Ξ']);

        // March 2026: +200 collected, −50 refunded → net 150.
        Payment::create(['company_id' => $this->tenant->id, 'customer_id' => $cust->id, 'pay_date' => '2026-03-05', 'amount' => 200, 'kind' => 'payment']);
        Payment::create(['company_id' => $this->tenant->id, 'customer_id' => $cust->id, 'pay_date' => '2026-03-20', 'amount' => 50, 'kind' => 'refund']);
        // July 2026: +300.
        Payment::create(['company_id' => $this->tenant->id, 'customer_id' => $cust->id, 'pay_date' => '2026-07-10', 'amount' => 300, 'kind' => 'payment']);
        // A 2025 receipt — must not land in the 2026 series.
        Payment::create(['company_id' => $this->tenant->id, 'customer_id' => $cust->id, 'pay_date' => '2025-07-10', 'amount' => 100, 'kind' => 'payment']);
        // Another tenant — excluded.
        Payment::create(['company_id' => $this->other->id, 'customer_id' => $otherCust->id, 'pay_date' => '2026-03-01', 'amount' => 999, 'kind' => 'payment']);

        $metrics = new DashboardMetrics($this->tenant);

        $y2026 = $metrics->receiptsByMonth(2026);
        $this->assertCount(12, $y2026);
        $this->assertEqualsWithDelta(150.0, $y2026[2], 0.001);  // March (index 2)
        $this->assertEqualsWithDelta(300.0, $y2026[6], 0.001);  // July (index 6)
        $this->assertEqualsWithDelta(0.0, $y2026[0], 0.001);    // January
        $this->assertEqualsWithDelta(450.0, array_sum($y2026), 0.001);

        // The 2025 receipt shows only in the 2025 series.
        $this->assertEqualsWithDelta(100.0, $metrics->receiptsByMonth(2025)[6], 0.001);
    }
}
