<?php

namespace Tests\Feature\Accounting;

use App\Actions\IssueCreditNote;
use App\Models\Company;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\InvoiceLine;
use App\Models\InvoiceType;
use App\Models\PaymentMethod;
use App\Services\Accounting\CustomerTrialBalanceReport;
use App\Services\CustomerLedger\CustomerLedgerBuilder;
use App\Services\Dashboard\DashboardMetrics;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * «Ισοζύγιο Πελατών» (#4): the per-customer period trial balance
 * (opening | debit | credit | closing) built on CustomerLedgerBuilder, so it
 * reconciles with the Καρτέλα balance and the dashboard receivables.
 */
class CustomerTrialBalanceTest extends TestCase
{
    use RefreshDatabase;

    private Company $tenant;

    private InvoiceType $type;

    private PaymentMethod $creditTerm;

    private static int $seq = 0;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-06-15 12:00:00'));

        $this->tenant = Company::create([
            'name' => 'TB', 'slug' => 'tb-'.uniqid(), 'country_code' => 'GR',
            'einvoice_provider' => 'gr-mydata', 'mydata_mode' => 'off',
        ]);
        $this->type = InvoiceType::create([
            'company_id' => $this->tenant->id, 'code' => 'TPY', 'name' => 'ΤΠΥ', 'invcount' => 0,
        ]);
        $this->creditTerm = PaymentMethod::create([
            'company_id' => $this->tenant->id, 'description' => 'Επί πιστώσει', 'due_days' => 30,
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function customer(string $name): Customer
    {
        return Customer::create(['company_id' => $this->tenant->id, 'name' => $name]);
    }

    private function invoice(Customer $c, string $issuedAt, float $gross, bool $creditTerm = true, bool $credit = false): Invoice
    {
        $inv = Invoice::create([
            'company_id' => $this->tenant->id, 'customer_id' => $c->id,
            'invoice_type_id' => ($credit ? $this->creditType() : $this->type)->id,
            'payment_method_id' => $creditTerm ? $this->creditTerm->id : null,
            'invcode' => 'I'.(++self::$seq), 'code' => self::$seq, 'issued_at' => $issuedAt,
            'net_total' => $gross, 'gross_total' => $gross, 'payable_total' => $gross,
            'local_status' => 'active',
        ]);
        // A line so IssueCreditNote (used in one test) has something to credit.
        InvoiceLine::create([
            'company_id' => $this->tenant->id, 'invoice_id' => $inv->id,
            'qty' => 1, 'price_per_item' => $gross, 'vat_percent' => 0, 'product_descr' => 'x',
        ]);

        return $inv;
    }

    private function creditType(): InvoiceType
    {
        return InvoiceType::create([
            'company_id' => $this->tenant->id, 'code' => 'PIS'.(++self::$seq), 'name' => 'ΠΙΣ',
            'invcount' => 0, 'is_credit' => true,
        ]);
    }

    private function payment(Customer $c, string $payDate, float $amount): void
    {
        DB::table('payments')->insert([
            'company_id' => $this->tenant->id, 'customer_id' => $c->id,
            'kind' => 'payment', 'pay_date' => $payDate, 'amount' => $amount,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function bal(Customer $c, string $from, string $to): array
    {
        return app(CustomerLedgerBuilder::class)->periodBalances(
            $c, Carbon::parse($from)->startOfDay(), Carbon::parse($to)->endOfDay(),
        );
    }

    public function test_opening_debit_credit_closing_bucket_by_date(): void
    {
        $c = $this->customer('Acme');
        $this->invoice($c, '2025-11-01', 100);   // before period → opening debit
        $this->invoice($c, '2026-03-10', 200);   // in period → debit
        $this->payment($c, '2026-03-20', 50);    // in period → credit

        $b = $this->bal($c, '2026-01-01', '2026-12-31');

        $this->assertEqualsWithDelta(100.0, $b['opening'], 0.01);
        $this->assertEqualsWithDelta(200.0, $b['debit'], 0.01);
        $this->assertEqualsWithDelta(50.0, $b['credit'], 0.01);
        $this->assertEqualsWithDelta(250.0, $b['closing'], 0.01); // 100 + 200 − 50
        // The row's arithmetic balances exactly.
        $this->assertEqualsWithDelta($b['opening'] + $b['debit'] - $b['credit'], $b['closing'], 0.001);
    }

    public function test_all_time_closing_equals_the_kartela_stats_balance(): void
    {
        // The load-bearing reconciliation: closing for an all-embracing window ==
        // the CustomerLedgerBuilder stats balance (what the Καρτέλα / receivables show).
        $c = $this->customer('Recon');
        $this->invoice($c, '2024-05-01', 300);
        $this->invoice($c, '2026-02-01', 150);
        $this->payment($c, '2026-02-10', 90);

        $closing = $this->bal($c, '2000-01-01', '2100-12-31')['closing'];
        $statsBalance = app(CustomerLedgerBuilder::class)->buildStatsBlock($c)['stats']['balance'];

        $this->assertEqualsWithDelta($statsBalance, $closing, 0.01);
        $this->assertEqualsWithDelta(360.0, $closing, 0.01); // 300 + 150 − 90
    }

    public function test_cash_term_invoice_without_payment_is_excluded(): void
    {
        $c = $this->customer('Cash');
        $this->invoice($c, '2026-03-01', 500, creditTerm: false); // cash-term, no payment

        $b = $this->bal($c, '2026-01-01', '2026-12-31');
        $this->assertEqualsWithDelta(0.0, $b['debit'], 0.01);
        $this->assertEqualsWithDelta(0.0, $b['closing'], 0.01);
    }

    public function test_credit_note_in_period_is_a_credit(): void
    {
        $c = $this->customer('CreditNote');
        $original = $this->invoice($c, '2026-03-01', 200);
        app(IssueCreditNote::class)(
            $original->fresh('lines'), $this->creditType(),
            [['line_id' => $original->lines()->first()->id, 'qty' => 1]],
        );

        $b = $this->bal($c, '2026-01-01', '2026-12-31');
        $this->assertEqualsWithDelta(200.0, $b['debit'], 0.01);
        $this->assertEqualsWithDelta(200.0, $b['credit'], 0.01); // the credit note
        $this->assertEqualsWithDelta(0.0, $b['closing'], 0.01);
    }

    public function test_report_drops_zero_rows_and_totals_reconcile_with_receivables(): void
    {
        $owes = $this->customer('Owes');
        $this->invoice($owes, '2026-03-01', 400); // credit-term, unpaid → owes 400

        $settled = $this->customer('Settled');
        $this->invoice($settled, '2026-03-01', 100);
        $this->payment($settled, '2026-03-05', 100); // opening 0, debit 100, credit 100, closing 0

        $neverTransacted = $this->customer('Ghost'); // no invoices/payments

        $result = app(CustomerTrialBalanceReport::class)->build(
            $this->tenant, Carbon::parse('2000-01-01')->startOfDay(), Carbon::parse('2100-12-31')->endOfDay(),
        );

        $names = array_map(fn ($r) => $r->customerName, $result->rows);
        $this->assertContains('Owes', $names);
        $this->assertContains('Settled', $names, 'a customer with movement but zero closing still shows');
        $this->assertNotContains('Ghost', $names, 'a customer with no activity is dropped');

        // Σ(Τελικό) reconciles with the dashboard receivables (no retail/null-customer here).
        $this->assertEqualsWithDelta(
            (new DashboardMetrics($this->tenant))->outstandingReceivables(),
            $result->totalClosing(),
            0.01,
        );
        $this->assertEqualsWithDelta(400.0, $result->totalClosing(), 0.01);
    }
}
