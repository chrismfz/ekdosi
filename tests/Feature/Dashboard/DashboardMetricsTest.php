<?php

namespace Tests\Feature\Dashboard;

use App\Models\Company;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\InvoiceType;
use App\Models\PaymentMethod;
use App\Models\Payment;
use App\Services\Dashboard\DashboardMetrics;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Aggregation logic behind the dashboard widgets. Widgets can't be
 * visually verified in this sandbox (no MariaDB/browser), so the
 * money math is pinned here against sqlite.
 */
class DashboardMetricsTest extends TestCase
{
    use RefreshDatabase;

    private Company $tenant;
    private Company $other;
    private InvoiceType $type;
    private PaymentMethod $credit;
    private PaymentMethod $cash;

    protected function setUp(): void
    {
        parent::setUp();
        // Fixed "now" so month/quarter/year windows are deterministic.
        Carbon::setTestNow(Carbon::parse('2026-05-15 12:00:00'));

        $this->tenant = $this->makeCompany('tenant');
        $this->other = $this->makeCompany('other');

        $this->credit = PaymentMethod::create([
            'company_id' => $this->tenant->id, 'description' => 'Πίστωση',
            'due_days' => 30,
        ]);
        $this->cash = PaymentMethod::create([
            'company_id' => $this->tenant->id, 'description' => 'Μετρητά',
            'due_days' => 0,
        ]);
        $this->type = InvoiceType::create([
            'company_id' => $this->tenant->id, 'name' => 'ΤΠΥ',
            'code' => 'ΤΠΥ', 'invcount' => 1, 'payment_method_id' => $this->cash->id,
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
            'country_code' => 'GR', 'einvoice_provider' => 'gr-mydata',
            'mydata_mode' => 'off',
        ]);
    }

    private function makeInvoice(array $attrs): Invoice
    {
        $state = $attrs['mydata_state'] ?? null;
        unset($attrs['mydata_state']);

        $invoice = Invoice::create(array_merge([
            'company_id'      => $this->tenant->id,
            'invcode'         => 'ΤΠΥ'.uniqid(),
            'code'            => 1,
            'invoice_type_id' => $this->type->id,
            'issued_at'       => '2026-05-10 10:00:00',
            'net_total'       => 100,
            'gross_total'     => 124,
        ], $attrs));

        // mydata_state isn't fillable (submitter-only) — force it.
        if ($state !== null) {
            $invoice->forceFill(['mydata_state' => $state])->save();
        }

        return $invoice;
    }

    public function test_income_sums_net_gross_vat_and_count_for_window(): void
    {
        $this->makeInvoice(['net_total' => 100, 'gross_total' => 124]);
        $this->makeInvoice(['net_total' => 200, 'gross_total' => 226]);

        $fig = (new DashboardMetrics($this->tenant))->income(
            Carbon::parse('2026-05-01')->startOfMonth(),
            Carbon::parse('2026-05-31')->endOfMonth(),
        );

        $this->assertSame(300.0, $fig->net);
        $this->assertSame(350.0, $fig->gross);
        $this->assertSame(50.0, $fig->vat);   // (124-100) + (226-200)
        $this->assertSame(2, $fig->count);
    }

    public function test_income_excludes_cancelled_but_includes_null_state(): void
    {
        $this->makeInvoice(['net_total' => 100, 'gross_total' => 124, 'mydata_state' => 'VALID']);
        $this->makeInvoice(['net_total' => 100, 'gross_total' => 124, 'mydata_state' => null]);
        $this->makeInvoice(['net_total' => 999, 'gross_total' => 999, 'mydata_state' => 'CANCELLED']);

        $fig = (new DashboardMetrics($this->tenant))->income(
            Carbon::parse('2026-05-01')->startOfMonth(),
            Carbon::parse('2026-05-31')->endOfMonth(),
        );

        $this->assertSame(200.0, $fig->net);   // VALID + null, NOT cancelled
        $this->assertSame(2, $fig->count);
    }

    public function test_income_is_tenant_scoped(): void
    {
        $this->makeInvoice(['net_total' => 100, 'gross_total' => 124]);
        // other tenant's invoice in the same window must not bleed in
        Invoice::create([
            'company_id' => $this->other->id, 'invcode' => 'X'.uniqid(), 'code' => 1,
            'invoice_type_id' => $this->type->id, 'issued_at' => '2026-05-10 10:00:00',
            'net_total' => 5000, 'gross_total' => 6200,
        ]);

        $fig = (new DashboardMetrics($this->tenant))->income(
            Carbon::parse('2026-05-01')->startOfMonth(),
            Carbon::parse('2026-05-31')->endOfMonth(),
        );

        $this->assertSame(100.0, $fig->net);
    }

    public function test_income_respects_date_window(): void
    {
        $this->makeInvoice(['issued_at' => '2026-05-10 10:00:00', 'net_total' => 100, 'gross_total' => 124]);
        $this->makeInvoice(['issued_at' => '2026-04-10 10:00:00', 'net_total' => 999, 'gross_total' => 999]);

        $fig = (new DashboardMetrics($this->tenant))->income(
            Carbon::parse('2026-05-01')->startOfMonth(),
            Carbon::parse('2026-05-31')->endOfMonth(),
        );

        $this->assertSame(100.0, $fig->net);
    }

    public function test_outstanding_counts_credit_term_only_minus_payments(): void
    {
        // credit-term invoice → counts toward receivables
        $this->makeInvoice(['payment_method_id' => $this->credit->id, 'gross_total' => 500]);
        // cash-term invoice → settled at issue, excluded
        $this->makeInvoice(['payment_method_id' => $this->cash->id, 'gross_total' => 300]);
        // cancelled credit invoice → excluded
        $this->makeInvoice(['payment_method_id' => $this->credit->id, 'gross_total' => 999, 'mydata_state' => 'CANCELLED']);

        Payment::create([
            'company_id' => $this->tenant->id,
            'customer_id' => Customer::create(['company_id' => $this->tenant->id, 'name' => 'P'])->id,
            'pay_date' => '2026-05-12', 'amount' => 200,
        ]);

        $outstanding = (new DashboardMetrics($this->tenant))->outstandingReceivables();

        $this->assertSame(300.0, $outstanding);   // 500 credit - 200 paid
    }

    public function test_top_debtors_lists_only_customers_who_owe_and_reconciles_with_headline(): void
    {
        $a = Customer::create(['company_id' => $this->tenant->id, 'name' => 'Οφειλέτης Α']);
        $b = Customer::create(['company_id' => $this->tenant->id, 'name' => 'Εξοφλημένος Β']);
        $cashCust = Customer::create(['company_id' => $this->tenant->id, 'name' => 'Μετρητοίς Γ']);

        // A: credit-term 500, paid 200 → owes 300 (a debtor).
        $this->makeInvoice(['customer_id' => $a->id, 'payment_method_id' => $this->credit->id, 'gross_total' => 500]);
        Payment::create(['company_id' => $this->tenant->id, 'customer_id' => $a->id, 'pay_date' => '2026-05-12', 'amount' => 200]);

        // B: credit-term 100, paid 100 → settled, NOT a debtor.
        $this->makeInvoice(['customer_id' => $b->id, 'payment_method_id' => $this->credit->id, 'gross_total' => 100]);
        Payment::create(['company_id' => $this->tenant->id, 'customer_id' => $b->id, 'pay_date' => '2026-05-12', 'amount' => 100]);

        // C: cash-term 999 → settled at issue, never a receivable.
        $this->makeInvoice(['customer_id' => $cashCust->id, 'payment_method_id' => $this->cash->id, 'gross_total' => 999]);

        // Cancelled credit invoice for A → excluded.
        $this->makeInvoice(['customer_id' => $a->id, 'payment_method_id' => $this->credit->id, 'gross_total' => 888, 'mydata_state' => 'CANCELLED']);

        $metrics = new DashboardMetrics($this->tenant);

        $debtors = $metrics->topDebtorsQuery()->get();
        $this->assertCount(1, $debtors);
        $this->assertSame($a->id, $debtors->first()->id);
        $this->assertSame(300.0, round((float) $debtors->first()->outstanding_balance, 2));

        $this->assertSame([$a->id], $metrics->debtorIds());

        // The per-customer view reconciles with the headline aggregate.
        $this->assertSame(300.0, $metrics->outstandingReceivables());
    }

    public function test_top_debtors_orders_by_balance_desc_and_is_tenant_scoped(): void
    {
        $big = Customer::create(['company_id' => $this->tenant->id, 'name' => 'Μεγάλος']);
        $small = Customer::create(['company_id' => $this->tenant->id, 'name' => 'Μικρός']);
        $this->makeInvoice(['customer_id' => $small->id, 'payment_method_id' => $this->credit->id, 'gross_total' => 100]);
        $this->makeInvoice(['customer_id' => $big->id, 'payment_method_id' => $this->credit->id, 'gross_total' => 900]);

        // Another tenant's debtor must never bleed into this tenant's list.
        $otherCredit = PaymentMethod::create([
            'company_id' => $this->other->id, 'description' => 'Πίστωση', 'due_days' => 30,
        ]);
        $otherType = InvoiceType::create([
            'company_id' => $this->other->id, 'name' => 'Τ', 'code' => 'Τ',
            'invcount' => 1, 'payment_method_id' => $otherCredit->id,
        ]);
        $otherCust = Customer::create(['company_id' => $this->other->id, 'name' => 'Ξένος']);
        Invoice::create([
            'company_id' => $this->other->id, 'invcode' => 'O'.uniqid(), 'code' => 1,
            'invoice_type_id' => $otherType->id, 'issued_at' => '2026-05-10 10:00:00',
            'net_total' => 1000, 'gross_total' => 1000,
            'customer_id' => $otherCust->id, 'payment_method_id' => $otherCredit->id,
        ]);

        $debtors = (new DashboardMetrics($this->tenant))->topDebtorsQuery()->get();

        $this->assertSame([$big->id, $small->id], $debtors->pluck('id')->all());
    }

    public function test_customers_list_balance_query_and_filter_run_on_the_db(): void
    {
        // Mirrors exactly what CustomersTable builds: withOutstandingBalance()
        // (via modifyQueryUsing) + the "Με υπόλοιπο" filter whereRaw +
        // sort by the aliased column. Catches SQL-level breakage (ambiguous
        // columns, ORDER BY on a select alias) without booting Filament.
        $debtor = Customer::create(['company_id' => $this->tenant->id, 'name' => 'Χρωστάει']);
        $settled = Customer::create(['company_id' => $this->tenant->id, 'name' => 'Τακτοποιημένος']);
        $this->makeInvoice(['customer_id' => $debtor->id, 'payment_method_id' => $this->credit->id, 'gross_total' => 250]);

        $rows = Customer::query()
            ->where('customers.company_id', $this->tenant->id)
            ->withOutstandingBalance($this->tenant->id)
            ->whereRaw('(COALESCE(cust_owed.owed, 0) - COALESCE(cust_paid.paid, 0)) > 0.005')
            ->orderByDesc('outstanding_balance')
            ->get();

        $this->assertSame([$debtor->id], $rows->pluck('id')->all());
        $this->assertSame(250.0, round((float) $rows->first()->outstanding_balance, 2));

        // The "Χωρίς υπόλοιπο" branch returns the settled customer.
        $settledRows = Customer::query()
            ->where('customers.company_id', $this->tenant->id)
            ->withOutstandingBalance($this->tenant->id)
            ->whereRaw('(COALESCE(cust_owed.owed, 0) - COALESCE(cust_paid.paid, 0)) <= 0.005')
            ->pluck('customers.id')
            ->all();

        $this->assertContains($settled->id, $settledRows);
        $this->assertNotContains($debtor->id, $settledRows);
    }

    public function test_monthly_income_window_anchors_on_given_end(): void
    {
        // Inside the anchored window…
        $this->makeInvoice(['issued_at' => '2026-03-10 10:00:00', 'net_total' => 100, 'gross_total' => 124]);
        // …after the anchor month → must be excluded.
        $this->makeInvoice(['issued_at' => '2026-05-10 10:00:00', 'net_total' => 999, 'gross_total' => 999]);

        $series = (new DashboardMetrics($this->tenant))
            ->monthlyIncome(3, Carbon::parse('2026-04-30'));

        $this->assertSame(['2026-02', '2026-03', '2026-04'], array_column($series, 'key'));
        $byKey = collect($series)->keyBy('key');
        $this->assertSame(100.0, $byKey['2026-03']['net']);
        // The May invoice is past the anchor → not in any bucket.
        $this->assertSame(0.0, $byKey['2026-04']['net']);
    }

    public function test_income_excludes_credit_notes(): void
    {
        // A sale this month.
        $sale = $this->makeInvoice(['net_total' => 100, 'gross_total' => 124]);
        // A credit note against it (positive gross) must NOT be counted
        // as income — otherwise sale + credit reads as 2× revenue.
        $this->makeInvoice(['net_total' => 100, 'gross_total' => 124, 'credited_invoice_id' => $sale->id]);

        $fig = (new DashboardMetrics($this->tenant))->income(
            Carbon::parse('2026-05-01')->startOfMonth(),
            Carbon::parse('2026-05-31')->endOfMonth(),
        );

        $this->assertSame(100.0, $fig->net);    // sale only, credit note excluded
        $this->assertSame(1, $fig->count);
    }

    public function test_outstanding_nets_out_valid_credit_notes(): void
    {
        // credit-term invoice of 500, with a VALID 200 credit note against it.
        $original = $this->makeInvoice([
            'payment_method_id' => $this->credit->id, 'gross_total' => 500, 'mydata_state' => 'VALID',
        ]);
        $original->forceFill(['credited_total' => 200])->save();   // cache as InvoiceBalance would

        // the credit note itself (credited_invoice_id set) must NOT count as a receivable
        $this->makeInvoice([
            'payment_method_id' => $this->credit->id, 'gross_total' => 200,
            'credited_invoice_id' => $original->id, 'mydata_state' => 'VALID',
        ]);

        $outstanding = (new DashboardMetrics($this->tenant))->outstandingReceivables();

        $this->assertSame(300.0, $outstanding);   // 500 gross - 200 credited - 0 paid
    }

    public function test_unfiled_counts_only_new_app_null_state(): void
    {
        $this->makeInvoice(['mydata_state' => null]);   // new-app draft → counts
        $this->makeInvoice(['mydata_state' => null]);   // new-app draft → counts
        $this->makeInvoice(['mydata_state' => 'VALID']);
        $this->makeInvoice(['mydata_state' => 'CANCELLED']);
        // ETL-imported legacy invoice, never filed → must NOT inflate the
        // "to file at myDATA" backlog (it predates myDATA).
        $this->makeInvoice(['mydata_state' => null, 'legacy_id' => 9001]);

        $this->assertSame(2, (new DashboardMetrics($this->tenant))->unfiledCount());
    }

    public function test_monthly_income_returns_dense_zero_filled_series(): void
    {
        $this->makeInvoice(['issued_at' => '2026-05-10 10:00:00', 'net_total' => 100, 'gross_total' => 124]);
        $this->makeInvoice(['issued_at' => '2026-03-10 10:00:00', 'net_total' => 50, 'gross_total' => 62]);

        $series = (new DashboardMetrics($this->tenant))->monthlyIncome(12);

        $this->assertCount(12, $series);
        // last bucket is the current month (2026-05)
        $this->assertSame('2026-05', $series[11]['key']);
        $this->assertSame(100.0, $series[11]['net']);
        $this->assertSame(24.0, $series[11]['vat']);
        // a month with no invoices is present and zeroed
        $april = collect($series)->firstWhere('key', '2026-04');
        $this->assertSame(0.0, $april['net']);
        // March had an invoice
        $march = collect($series)->firstWhere('key', '2026-03');
        $this->assertSame(50.0, $march['net']);
    }

    public function test_cumulative_net_by_month_runs_a_running_total(): void
    {
        $this->makeInvoice(['issued_at' => '2026-01-10 10:00:00', 'net_total' => 100, 'gross_total' => 124]);
        $this->makeInvoice(['issued_at' => '2026-03-10 10:00:00', 'net_total' => 50, 'gross_total' => 62]);

        $cum = (new DashboardMetrics($this->tenant))->cumulativeNetByMonth(2026);

        $this->assertCount(12, $cum);
        $this->assertSame(100.0, $cum[0]);   // Jan
        $this->assertSame(100.0, $cum[1]);   // Feb (no new) stays
        $this->assertSame(150.0, $cum[2]);   // Mar adds 50
        $this->assertSame(150.0, $cum[11]);  // Dec still 150
    }

    public function test_top_customers_query_ranks_by_gross_excluding_cancelled(): void
    {
        $big = Customer::create(['company_id' => $this->tenant->id, 'name' => 'Big', 'afm' => '111']);
        $small = Customer::create(['company_id' => $this->tenant->id, 'name' => 'Small', 'afm' => '222']);

        $this->makeInvoice(['customer_id' => $big->id, 'issued_at' => '2026-02-01 10:00:00', 'gross_total' => 1000]);
        $this->makeInvoice(['customer_id' => $small->id, 'issued_at' => '2026-02-01 10:00:00', 'gross_total' => 100]);
        // cancelled big invoice must NOT inflate Big's total
        $this->makeInvoice(['customer_id' => $big->id, 'issued_at' => '2026-02-01 10:00:00', 'gross_total' => 9999, 'mydata_state' => 'CANCELLED']);

        // A customer with NO invoices this year must not pad the list.
        Customer::create(['company_id' => $this->tenant->id, 'name' => 'Idle', 'afm' => '333']);

        $rows = (new DashboardMetrics($this->tenant))->topCustomersQuery(
            Carbon::parse('2026-01-01')->startOfYear(),
            Carbon::parse('2026-12-31')->endOfYear(),
            10,
        )->get();

        $this->assertCount(2, $rows);   // Idle excluded
        $this->assertSame('Big', $rows[0]->name);
        $this->assertSame(1000.0, (float) $rows[0]->gross_ytd);
        $this->assertSame(1, (int) $rows[0]->invoices_ytd);   // cancelled one excluded
        $this->assertSame('Small', $rows[1]->name);
    }

    public function test_available_years_lists_distinct_years_desc_with_current_always_present(): void
    {
        $this->makeInvoice(['issued_at' => '2024-07-10 10:00:00']);
        $this->makeInvoice(['issued_at' => '2026-02-10 10:00:00']);
        // a cancelled invoice's year must not appear on its own
        $this->makeInvoice(['issued_at' => '2022-01-10 10:00:00', 'mydata_state' => 'CANCELLED']);

        $years = (new DashboardMetrics($this->tenant))->availableYears();

        // 2026 (current, has an invoice) + 2024; 2022 only cancelled → absent.
        $this->assertSame([2026, 2024], $years);
    }

    public function test_monthly_for_year_is_dense_and_non_cumulative(): void
    {
        $this->makeInvoice(['issued_at' => '2026-03-10 10:00:00', 'net_total' => 50, 'gross_total' => 62]);
        $this->makeInvoice(['issued_at' => '2026-05-10 10:00:00', 'net_total' => 100, 'gross_total' => 124]);
        // prior year must not bleed into this year's months
        $this->makeInvoice(['issued_at' => '2025-05-10 10:00:00', 'net_total' => 999, 'gross_total' => 999]);

        $rows = (new DashboardMetrics($this->tenant))->monthlyForYear(2026);

        $this->assertCount(12, $rows);
        $this->assertSame(50.0, $rows[2]['net']);    // March
        $this->assertSame(12.0, $rows[2]['vat']);    // 62-50
        $this->assertSame(100.0, $rows[4]['net']);   // May (NOT cumulative)
        $this->assertSame(0.0, $rows[0]['net']);     // January empty
    }

    public function test_yearly_totals_are_dense_and_tenant_scoped(): void
    {
        $this->makeInvoice(['issued_at' => '2024-06-10 10:00:00', 'net_total' => 100, 'gross_total' => 124]);
        $this->makeInvoice(['issued_at' => '2026-06-10 10:00:00', 'net_total' => 300, 'gross_total' => 372]);
        // other tenant's invoice must not leak into the totals
        Invoice::create([
            'company_id' => $this->other->id, 'invcode' => 'X'.uniqid(), 'code' => 1,
            'invoice_type_id' => $this->type->id, 'issued_at' => '2026-06-10 10:00:00',
            'net_total' => 9999, 'gross_total' => 9999,
        ]);

        $rows = (new DashboardMetrics($this->tenant))->yearlyTotals(3, 2026);

        $this->assertSame([2024, 2025, 2026], array_column($rows, 'year'));
        $byYear = collect($rows)->keyBy('year');
        $this->assertSame(100.0, $byYear[2024]['net']);
        $this->assertSame(0.0, $byYear[2025]['net']);   // empty year zero-filled
        $this->assertSame(300.0, $byYear[2026]['net']);
        $this->assertSame(1, $byYear[2026]['count']);
    }

    public function test_kpi_summary_year_to_date_yoy_and_quality_ratios(): void
    {
        $cust = Customer::create(['company_id' => $this->tenant->id, 'name' => 'Πελάτης Α', 'afm' => '1']);

        // Current-year (2026) sale, within YTD (now = 2026-05-15).
        $sale = $this->makeInvoice([
            'customer_id' => $cust->id, 'issued_at' => '2026-05-10 10:00:00',
            'net_total' => 200, 'gross_total' => 248,
        ]);
        // Prior-year SAME-SPAN sale (Jan–May15 2025) → the YoY baseline.
        $this->makeInvoice(['issued_at' => '2025-03-10 10:00:00', 'net_total' => 100, 'gross_total' => 124]);
        // A credit note in 2026 → feeds the credit-rate KPI, excluded from income.
        $this->makeInvoice([
            'issued_at' => '2026-04-10 10:00:00', 'net_total' => 40, 'gross_total' => 48,
            'credited_invoice_id' => $sale->id,
        ]);

        $k = (new DashboardMetrics($this->tenant))->kpiSummary(2026);

        $this->assertSame(200.0, $k['net']);          // sale only (credit note excluded)
        $this->assertSame(1, $k['count']);
        $this->assertSame(100.0, $k['priorNet']);
        $this->assertSame(100.0, $k['yoyPct']);       // (200-100)/100
        $this->assertSame(40.0, $k['avgMonthlyNet']); // 200 / 5 months elapsed
        $this->assertSame(200.0, $k['avgInvoiceNet']);
        $this->assertSame(48.0, $k['creditGross']);
        $this->assertSame(19.4, $k['creditRatioPct']);   // 48 / 248
        $this->assertSame('Πελάτης Α', $k['topCustomerName']);
        $this->assertSame(100.0, $k['topCustomerShare']); // 248 / 248
        // cash-term sale → no receivable → DSO snapshot is zero, not null.
        $this->assertSame(0, $k['dsoDays']);
    }

    public function test_kpi_summary_handles_no_prior_year(): void
    {
        $this->makeInvoice(['issued_at' => '2026-05-10 10:00:00', 'net_total' => 200, 'gross_total' => 248]);

        $k = (new DashboardMetrics($this->tenant))->kpiSummary(2026);

        $this->assertNull($k['yoyPct']);              // no 2025 sales → no comparison
        $this->assertSame(0.0, $k['creditRatioPct']);
    }

    public function test_monthly_income_does_not_overflow_on_month_end_days(): void
    {
        // Viewed on the 31st, a plain subMonths(11) would skip June 2025
        // and append a future month. subMonthsNoOverflow keeps the window
        // honest: it must run 2025-06 .. 2026-05.
        Carbon::setTestNow(Carbon::parse('2026-05-31 12:00:00'));

        $series = (new DashboardMetrics($this->tenant))->monthlyIncome(12);

        $this->assertCount(12, $series);
        $this->assertSame('2025-06', $series[0]['key']);
        $this->assertSame('2026-05', $series[11]['key']);   // current month, not a future one
    }
}
