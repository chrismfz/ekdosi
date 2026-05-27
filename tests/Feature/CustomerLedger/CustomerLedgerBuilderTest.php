<?php

namespace Tests\Feature\CustomerLedger;

use App\Models\Company;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\InvoiceType;
use App\Models\Payment;
use App\Models\PaymentMethod;
use App\Services\CustomerLedger\CustomerLedgerBuilder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Καρτέλα Πελάτη math: balance respects credit-term semantics, aging
 * buckets allocate FIFO, yearly running balance compounds across years,
 * chronological ledger preserves history when filtered.
 */
class CustomerLedgerBuilderTest extends TestCase
{
    use RefreshDatabase;

    private Company $tenant;
    private PaymentMethod $cash;
    private PaymentMethod $credit;
    private InvoiceType $invType;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Company::create([
            'name' => 'Test',
            'slug' => 'k-'.uniqid(),
            'country_code' => 'GR',
            'einvoice_provider' => 'gr-mydata',
            'mydata_mode' => 'off',
        ]);

        $this->cash = PaymentMethod::create([
            'company_id' => $this->tenant->id,
            'name' => 'Cash',
            'due_days' => 0,    // cash term - doesn't count toward balance
            'is_active' => true,
        ]);
        $this->credit = PaymentMethod::create([
            'company_id' => $this->tenant->id,
            'name' => 'Credit 30d',
            'due_days' => 30,   // credit term - DOES count
            'is_active' => true,
        ]);
        $this->invType = InvoiceType::create([
            'company_id' => $this->tenant->id,
            'name' => 'Test Invoice',
            'code' => 'TST',
            'invcount' => 0,
            'payment_method_id' => $this->credit->id,
        ]);
    }

    private function makeCustomer(): Customer
    {
        return Customer::create([
            'company_id' => $this->tenant->id,
            'name' => 'K',
        ]);
    }

    private static int $invSeq = 0;

    private function makeInvoice(Customer $c, string $issuedAt, float $gross, PaymentMethod $pm, ?float $net = null): Invoice
    {
        // invcode is NOT NULL in production schema; normally set by
        // InvoiceNumberer at issue time. Tests bypass the numberer
        // so seed a unique value here.
        self::$invSeq++;
        return Invoice::create([
            'company_id' => $this->tenant->id,
            'customer_id' => $c->id,
            'invoice_type_id' => $this->invType->id,
            'payment_method_id' => $pm->id,
            'invcode' => 'TEST'.self::$invSeq,
            'code' => self::$invSeq,
            'issued_at' => $issuedAt,
            'gross_total' => $gross,
            'net_total' => $net ?? round($gross / 1.24, 2),
        ]);
    }

    private function makePayment(Customer $c, string $payDate, float $amount): Payment
    {
        return Payment::create([
            'company_id' => $this->tenant->id,
            'customer_id' => $c->id,
            'pay_date' => $payDate,
            'amount' => $amount,
        ]);
    }

    public function test_empty_customer_returns_zeroed_stats(): void
    {
        $c = $this->makeCustomer();
        $r = app(CustomerLedgerBuilder::class)->build($c);

        $this->assertSame(0.0, $r->stats['balance']);
        $this->assertSame(0, $r->stats['total_invoices_lifetime']);
        $this->assertFalse($r->hasAnyActivity());
    }

    public function test_cash_term_invoices_do_not_count_toward_balance(): void
    {
        // Legacy GET_CUSTOMER_BALANCE semantics: only credit-term
        // invoices add to receivables. Cash-term are settled at issue.
        $c = $this->makeCustomer();
        $this->makeInvoice($c, '2026-05-01', 100.0, $this->cash);
        $this->makeInvoice($c, '2026-05-02', 200.0, $this->cash);

        $r = app(CustomerLedgerBuilder::class)->build($c);

        $this->assertSame(0.0, $r->stats['balance']);
        $this->assertSame(2, $r->stats['total_invoices_lifetime']);
    }

    public function test_credit_term_invoice_creates_balance_until_paid(): void
    {
        $c = $this->makeCustomer();
        $this->makeInvoice($c, '2026-05-01', 100.0, $this->credit);

        $r = app(CustomerLedgerBuilder::class)->build($c);
        $this->assertSame(100.0, $r->stats['balance']);

        $this->makePayment($c, '2026-05-15', 100.0);
        $r2 = app(CustomerLedgerBuilder::class)->build($c);
        $this->assertSame(0.0, $r2->stats['balance']);
    }

    public function test_ytd_only_counts_current_year(): void
    {
        $c = $this->makeCustomer();
        $this->makeInvoice($c, '2025-12-31', 100.0, $this->cash, 80.0);
        $this->makeInvoice($c, now()->format('Y-01-15'), 200.0, $this->cash, 160.0);

        $r = app(CustomerLedgerBuilder::class)->build($c);

        $this->assertSame(200.0, $r->stats['ytd_gross']);
        $this->assertSame(160.0, $r->stats['ytd_net']);
    }

    public function test_aging_buckets_allocate_fifo_oldest_first(): void
    {
        // Three credit-term invoices, one payment that partially
        // settles the oldest. The remaining outstanding amount should
        // be distributed across buckets by invoice age.
        $c = $this->makeCustomer();
        $today = now();
        $this->makeInvoice($c, $today->copy()->subDays(120)->toDateString(), 100.0, $this->credit);
        $this->makeInvoice($c, $today->copy()->subDays(75)->toDateString(), 200.0, $this->credit);
        $this->makeInvoice($c, $today->copy()->subDays(15)->toDateString(), 50.0, $this->credit);

        // Pay 100 - FIFO settles the oldest (the 120-day-old 100).
        $this->makePayment($c, $today->copy()->subDays(10)->toDateString(), 100.0);

        $r = app(CustomerLedgerBuilder::class)->build($c);

        $this->assertSame(0.0, $r->aging['bucket_90_plus']);      // oldest settled
        $this->assertSame(200.0, $r->aging['bucket_61_90']);      // mid-age unpaid
        $this->assertSame(0.0, $r->aging['bucket_31_60']);
        $this->assertSame(50.0, $r->aging['bucket_0_30']);
        $this->assertSame(250.0, $r->stats['balance']);
    }

    public function test_yearly_breakdown_running_balance_compounds(): void
    {
        $c = $this->makeCustomer();
        $this->makeInvoice($c, '2024-06-01', 100.0, $this->credit);  // year-end 2024: +100
        $this->makeInvoice($c, '2025-06-01', 200.0, $this->credit);
        $this->makePayment($c, '2025-12-01', 50.0);                  // year-end 2025: +250

        $r = app(CustomerLedgerBuilder::class)->build($c);

        // Newest year first per the builder.
        $this->assertSame(2025, $r->yearly[0]['year']);
        $this->assertSame(250.0, $r->yearly[0]['year_end_balance']);
        $this->assertSame(2024, $r->yearly[1]['year']);
        $this->assertSame(100.0, $r->yearly[1]['year_end_balance']);
    }

    public function test_chronological_ledger_running_balance_walks_history(): void
    {
        $c = $this->makeCustomer();
        $this->makeInvoice($c, '2026-01-01', 100.0, $this->credit);
        $this->makeInvoice($c, '2026-02-01', 200.0, $this->credit);
        $this->makePayment($c, '2026-03-01', 150.0);
        $this->makeInvoice($c, '2026-04-01', 50.0, $this->credit);

        $r = app(CustomerLedgerBuilder::class)->build($c);

        // Newest first in output. Running balance at each row reflects
        // cumulative state through that row's date.
        $this->assertCount(4, $r->ledger);
        $this->assertSame('2026-04-01', $r->ledger[0]['date']);
        $this->assertSame(200.0, $r->ledger[0]['running_balance']);   // 100+200-150+50
        $this->assertSame('2026-03-01', $r->ledger[1]['date']);
        $this->assertSame(150.0, $r->ledger[1]['running_balance']);   // 100+200-150
        $this->assertSame('2026-02-01', $r->ledger[2]['date']);
        $this->assertSame(300.0, $r->ledger[2]['running_balance']);   // 100+200
        $this->assertSame('2026-01-01', $r->ledger[3]['date']);
        $this->assertSame(100.0, $r->ledger[3]['running_balance']);
    }

    public function test_chronological_ledger_year_filter_preserves_running_balance_from_history(): void
    {
        // Filter to 2026 only, but the running balance shown for 2026
        // rows must include carry-over from 2025 - operator wouldn't
        // expect a year filter to reset the balance to zero.
        $c = $this->makeCustomer();
        $this->makeInvoice($c, '2025-06-01', 1000.0, $this->credit);
        $this->makeInvoice($c, '2026-03-01', 200.0, $this->credit);

        $r = app(CustomerLedgerBuilder::class)->build($c, ['year' => 2026]);

        $this->assertCount(1, $r->ledger);
        // 2025 invoice not shown, but its 1000 is reflected in the
        // running balance of the 2026 row.
        $this->assertSame('2026-03-01', $r->ledger[0]['date']);
        $this->assertSame(1200.0, $r->ledger[0]['running_balance']);
    }

    public function test_oldest_unpaid_days_returns_null_when_settled(): void
    {
        $c = $this->makeCustomer();
        $this->makeInvoice($c, '2026-05-01', 100.0, $this->credit);
        $this->makePayment($c, '2026-05-15', 100.0);

        $r = app(CustomerLedgerBuilder::class)->build($c);

        $this->assertNull($r->stats['oldest_unpaid_days']);
    }

    public function test_different_tenants_do_not_mix(): void
    {
        $c1 = $this->makeCustomer();
        $this->makeInvoice($c1, '2026-05-01', 100.0, $this->credit);

        // Different tenant + customer; should not appear in c1's ledger.
        $otherTenant = Company::create([
            'name' => 'Other', 'slug' => 'o-'.uniqid(),
            'country_code' => 'GR', 'einvoice_provider' => 'gr-mydata', 'mydata_mode' => 'off',
        ]);
        $otherPm = PaymentMethod::create(['company_id' => $otherTenant->id, 'name' => 'X', 'due_days' => 30, 'is_active' => true]);
        $otherInvType = InvoiceType::create([
            'company_id' => $otherTenant->id, 'name' => 'X', 'code' => 'XX', 'invcount' => 0,
            'payment_method_id' => $otherPm->id,
        ]);
        $c2 = Customer::create(['company_id' => $otherTenant->id, 'name' => 'Other']);

        // Cross-tenant fixture: directly insert (sidestep $this->tenant
        // assumption in makeInvoice).
        DB::table('invoices')->insert([
            'company_id' => $otherTenant->id,
            'customer_id' => $c2->id,
            'invoice_type_id' => $otherInvType->id,
            'payment_method_id' => $otherPm->id,
            'invcode' => 'XTEN1',
            'code' => 1,
            'issued_at' => '2026-05-01 00:00:00',
            'gross_total' => 9999.0,
            'net_total' => 8000.0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $r = app(CustomerLedgerBuilder::class)->build($c1);

        $this->assertSame(100.0, $r->stats['balance']);
        $this->assertSame(1, $r->stats['total_invoices_lifetime']);
    }
}
