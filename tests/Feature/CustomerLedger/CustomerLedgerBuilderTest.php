<?php

namespace Tests\Feature\CustomerLedger;

use App\Models\Company;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\InvoiceType;
use App\Models\Payment;
use App\Models\PaymentMethod;
use App\Services\CustomerLedger\CustomerLedgerBuilder;
use App\Services\CustomerLedger\CustomerStatementCsv;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
            'local_status' => 'active',   // MON-5: issued (unissued drafts don't count)
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

    public function test_soft_deleted_payment_does_not_count_toward_balance(): void
    {
        $c = $this->makeCustomer();
        $this->makeInvoice($c, '2026-05-01', 124.0, $this->credit);
        $p = $this->makePayment($c, '2026-05-02', 124.0);

        $this->assertSame(0.0, app(CustomerLedgerBuilder::class)->build($c)->stats['balance']);

        $p->delete();   // soft delete — must stop reducing the balance

        $this->assertSame(124.0, app(CustomerLedgerBuilder::class)->build($c)->stats['balance']);
    }

    public function test_zero_or_null_amount_payments_do_not_create_empty_ledger_rows(): void
    {
        // #377: legacy ETL raw-inserts bypassed the form's minValue(0.01) guard and
        // left 0/NULL-amount payments → empty ledger rows. They must be skipped.
        $c = $this->makeCustomer();
        $this->makeInvoice($c, '2026-01-01', 124.0, $this->credit);
        $this->makePayment($c, '2026-01-02', 100.0);   // a real payment — shows

        // The model's MON-8 guard rejects amount ≤ 0, so blanks can only arrive via
        // the ETL's raw insert (Eloquent bypassed) — reproduce that here.
        foreach ([['2026-01-03', 0], ['2026-01-04', null]] as [$date, $amount]) {
            DB::table('payments')->insert([
                'company_id' => $this->tenant->id, 'customer_id' => $c->id,
                'kind' => 'payment', 'pay_date' => $date, 'amount' => $amount,
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        $ledger = app(CustomerLedgerBuilder::class)->build($c)->ledger;
        $paymentRows = array_values(array_filter(
            $ledger,
            fn ($e) => in_array($e['type'], ['payment', 'refund'], true),
        ));

        $this->assertCount(1, $paymentRows);
        $this->assertSame(100.0, $paymentRows[0]['credit']);
    }

    public function test_chronological_ledger_is_oldest_first_for_statements(): void
    {
        $c = $this->makeCustomer();
        $this->makeInvoice($c, '2020-01-01', 124.0, $this->credit);
        $this->makeInvoice($c, '2025-01-01', 124.0, $this->credit);

        $result = app(CustomerLedgerBuilder::class)->build($c);

        // Operator-table default stays newest-first.
        $this->assertSame('2025-01-01', $result->ledger[0]['date']);
        // Statement reading is oldest→newest.
        $chrono = $result->chronologicalLedger();
        $this->assertSame('2020-01-01', $chrono[0]['date']);
        $this->assertSame('2025-01-01', $chrono[count($chrono) - 1]['date']);
    }

    public function test_csv_export_renders_chronological(): void
    {
        $c = $this->makeCustomer();
        $this->makeInvoice($c, '2020-01-01', 124.0, $this->credit);
        $this->makeInvoice($c, '2025-01-01', 124.0, $this->credit);

        $csv = app(CustomerStatementCsv::class)->build($c);

        // The older date must appear BEFORE the newer one in the exported file.
        $this->assertLessThan(strpos($csv, '2025-01-01'), strpos($csv, '2020-01-01'));
    }

    public function test_ledger_rows_carry_cash_credit_term_and_payment_presence(): void
    {
        // Display-only «Κατάσταση» hints — so the operator SEES why the balance
        // moved or not. No effect on the money math (asserted elsewhere).
        $c = $this->makeCustomer();
        $credit = $this->makeInvoice($c, '2026-01-01', 124.0, $this->credit);   // credit-term, no payment
        $cash = $this->makeInvoice($c, '2026-01-02', 62.0, $this->cash);        // cash-term, settled at issue
        $cashPaid = $this->makeInvoice($c, '2026-01-03', 50.0, $this->cash);    // cash-term WITH a real payment
        Payment::create([
            'company_id' => $this->tenant->id,
            'customer_id' => $c->id,
            'invoice_id' => $cashPaid->id,
            'pay_date' => '2026-01-03',
            'amount' => 50.0,
        ]);

        $ledger = collect(app(CustomerLedgerBuilder::class)->build($c)->ledger)->keyBy('reference');

        $this->assertSame('credit', $ledger[$credit->invcode]['payment_term']);
        $this->assertFalse($ledger[$credit->invcode]['has_payment']);

        $this->assertSame('cash', $ledger[$cash->invcode]['payment_term']);
        $this->assertFalse($ledger[$cash->invcode]['has_payment']);

        $this->assertSame('cash', $ledger[$cashPaid->invcode]['payment_term']);
        $this->assertTrue($ledger[$cashPaid->invcode]['has_payment']);

        // A payment/refund row carries no term (invoice-only hint).
        $paymentRow = collect(app(CustomerLedgerBuilder::class)->build($c)->ledger)
            ->firstWhere('type', 'payment');
        $this->assertNull($paymentRow['payment_term']);
        $this->assertFalse($paymentRow['has_payment']);
    }

    public function test_same_day_payment_and_refund_order_by_creation_and_carry_link_ids(): void
    {
        // Two money rows on the SAME date (a payment, then a refund entered later).
        // Without the creation-order tiebreak they could flip, and the running
        // balance would read out of order (the operator's «ανάποδα» complaint).
        $c = $this->makeCustomer();
        $inv = Invoice::create([
            'company_id' => $this->tenant->id, 'invcode' => 'TST1', 'code' => 1,
            'invoice_type_id' => $this->invType->id, 'customer_id' => $c->id,
            'payment_method_id' => $this->credit->id, 'issued_at' => '2026-05-01 10:00:00',
            'local_status' => 'active',
        ]);
        $inv->forceFill(['net_total' => 200, 'gross_total' => 200, 'payable_total' => 200])->save();

        $pay = Payment::create([
            'company_id' => $this->tenant->id, 'customer_id' => $c->id,
            'amount' => 100, 'pay_date' => '2026-05-10', 'kind' => 'payment',
        ]);
        $pay->forceFill(['created_at' => '2026-05-10 10:00:00'])->save();
        $refund = Payment::create([
            'company_id' => $this->tenant->id, 'customer_id' => $c->id,
            'amount' => 30, 'pay_date' => '2026-05-10', 'kind' => 'refund',
        ]);
        $refund->forceFill(['created_at' => '2026-05-10 11:00:00'])->save();

        $rows = app(CustomerLedgerBuilder::class)->build($c)->ledger;
        $money = array_values(array_filter($rows, fn ($r) => in_array($r['type'], ['payment', 'refund'], true)));

        // Newest-first: the LATER-created refund sits on top of the same-day payment.
        $this->assertSame('refund', $money[0]['type']);
        $this->assertSame($refund->id, $money[0]['payment_id']);   // links to its editable record
        $this->assertSame('payment', $money[1]['type']);
        $this->assertSame($pay->id, $money[1]['payment_id']);

        // Running balance is correct in that order: 200 − 100 = 100, then + 30 refund = 130.
        $this->assertEqualsWithDelta(130.0, $money[0]['running_balance'], 0.001);
        $this->assertEqualsWithDelta(100.0, $money[1]['running_balance'], 0.001);

        // Entry time surfaced for the ledger's date column.
        $this->assertSame('11:00', $money[0]['time']);
        $this->assertSame('10:00', $money[1]['time']);
    }

    public function test_cancelled_credit_note_does_not_reduce_ledger_balance(): void
    {
        $c = $this->makeCustomer();
        $original = $this->makeInvoice($c, '2026-05-01', 124.0, $this->credit);
        $original->forceFill(['mydata_state' => 'VALID'])->save();

        // A VALID credit note reduces the balance to 0.
        $credit = $this->makeInvoice($c, '2026-05-03', 124.0, $this->credit);
        $credit->forceFill(['credited_invoice_id' => $original->id, 'mydata_state' => 'VALID'])->save();
        $this->assertSame(0.0, app(CustomerLedgerBuilder::class)->build($c)->stats['balance']);

        // Cancelling it must restore the receivable (was diverging from
        // InvoiceBalance before the fix).
        $credit->forceFill(['mydata_state' => 'CANCELLED'])->save();
        $this->assertSame(124.0, app(CustomerLedgerBuilder::class)->build($c)->stats['balance']);
    }

    public function test_withholding_invoice_surfaces_an_analytic_breakdown_without_changing_the_math(): void
    {
        // A credit-term service invoice: document value 1240 (net+VAT), 200
        // withheld → collectible 1040. The ledger row keeps debit/balance = 1040
        // (the receivable), but exposes the document value + the παρακράτηση so the
        // Καρτέλα can show «Αξία εγγράφου 1.240 · Παρακράτηση φόρου 200».
        $c = $this->makeCustomer();
        $inv = $this->makeInvoice($c, '2026-05-01', 1240.0, $this->credit, net: 1000.0);
        $inv->forceFill(['payable_total' => 1040.0])->save();

        $result = app(CustomerLedgerBuilder::class)->build($c);

        // Money unchanged: receivable balance = the collectible.
        $this->assertSame(1040.0, $result->stats['balance']);

        $row = collect($result->ledger)->firstWhere('invoice_id', $inv->id);
        $this->assertSame(1040.0, $row['debit']);            // running balance honest
        $this->assertSame(1040.0, $row['running_balance']);
        $this->assertSame(1240.0, $row['document_gross']);   // the document value
        $this->assertSame(-200.0, $row['tax_adjustment']);   // payable − gross

        $detail = CustomerLedgerBuilder::adjustmentDetail(
            $row['document_gross'], $row['tax_adjustment'], fn ($v) => number_format($v, 2, ',', '.').' €'
        );
        $this->assertStringContainsString('Αξία εγγράφου 1.240,00 €', $detail);
        $this->assertStringContainsString('Παρακράτηση φόρου 200,00 €', $detail);

        // A plain invoice (no adjustment) yields no detail line.
        $plain = $this->makeInvoice($c, '2026-05-02', 124.0, $this->credit);
        $plainRow = collect(app(CustomerLedgerBuilder::class)->build($c)->ledger)->firstWhere('invoice_id', $plain->id);
        $this->assertSame(0.0, $plainRow['tax_adjustment']);
        $this->assertNull(CustomerLedgerBuilder::adjustmentDetail(
            $plainRow['document_gross'], $plainRow['tax_adjustment'], fn ($v) => (string) $v
        ));
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

    public function test_oldest_unpaid_days_skips_already_paid_credit_term_invoices(): void
    {
        // Regression: previously the stat returned MIN(issued_at) of
        // ALL credit-term invoices regardless of whether each was
        // paid. So a 2018 credit invoice fully paid in 2018 would
        // win over a 2026 unpaid invoice — showing operators an
        // 8-year-old "oldest unpaid" ghost debt.
        $c = $this->makeCustomer();
        $this->makeInvoice($c, '2018-01-01', 1000.0, $this->credit);
        $this->makePayment($c, '2018-01-10', 1000.0);   // settles 2018
        $this->makeInvoice($c, now()->subDays(5)->toDateString(), 500.0, $this->credit);   // 5 days old, unpaid

        $r = app(CustomerLedgerBuilder::class)->build($c);

        $this->assertSame(500.0, $r->stats['balance']);
        // Should be ~5 days, not ~8 years (2920 days).
        $this->assertNotNull($r->stats['oldest_unpaid_days']);
        $this->assertLessThanOrEqual(6, $r->stats['oldest_unpaid_days']);
    }

    public function test_build_stats_block_returns_only_filter_independent_sections(): void
    {
        // Locked-in contract: the optimization path (cache stats/aging/
        // yearly across filter changes in the Filament page) depends
        // on this method NOT including the chronological ledger.
        $c = $this->makeCustomer();
        $this->makeInvoice($c, '2026-05-01', 100.0, $this->credit);

        $block = app(CustomerLedgerBuilder::class)->buildStatsBlock($c);

        $this->assertArrayHasKey('stats', $block);
        $this->assertArrayHasKey('aging', $block);
        $this->assertArrayHasKey('yearly', $block);
        $this->assertArrayNotHasKey('ledger', $block);
    }

    public function test_build_ledger_only_respects_filters(): void
    {
        $c = $this->makeCustomer();
        $this->makeInvoice($c, '2025-06-01', 100.0, $this->credit);
        $this->makeInvoice($c, '2026-03-01', 200.0, $this->credit);

        $ledger = app(CustomerLedgerBuilder::class)->buildLedgerOnly($c, ['year' => 2026]);

        $this->assertCount(1, $ledger);
        $this->assertSame('2026-03-01', $ledger[0]['date']);
        // Running balance still reflects full history (1000 + 200 carry).
        $this->assertSame(300.0, $ledger[0]['running_balance']);
    }

    public function test_oldest_unpaid_days_walks_invoices_in_chronological_order_regardless_of_loader_ordering(): void
    {
        // Regression for the defensive-sort in computeStats: the FIFO
        // walk explicitly sorts by issued_at so a future change to
        // loadInvoices' ORDER BY can't silently flip FIFO to LIFO.
        $c = $this->makeCustomer();
        // Issue in NON-chronological CREATION order to simulate a
        // future loadInvoices that orders by id instead of issued_at.
        $this->makeInvoice($c, '2026-03-01', 50.0, $this->credit);   // newer first
        $this->makeInvoice($c, '2020-01-01', 200.0, $this->credit);  // older second
        $this->makePayment($c, '2020-02-01', 100.0);   // partial-pay on the 2020 one

        $r = app(CustomerLedgerBuilder::class)->build($c);

        // Balance: 250 - 100 = 150 outstanding.
        $this->assertSame(150.0, $r->stats['balance']);
        // FIFO: payment settles 100 of the 200 oldest invoice → that
        // 2020 invoice still has 100 unpaid → it IS the oldest unpaid.
        // Should be a multi-year-old number, not a few-months one.
        $this->assertNotNull($r->stats['oldest_unpaid_days']);
        $this->assertGreaterThan(365, $r->stats['oldest_unpaid_days']);
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
