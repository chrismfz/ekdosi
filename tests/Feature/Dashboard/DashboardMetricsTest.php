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
