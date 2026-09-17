<?php

namespace Tests\Feature\Accounting;

use App\Models\BankAccount;
use App\Models\Company;
use App\Models\Customer;
use App\Models\Payment;
use App\Models\PaymentMethod;
use App\Services\Accounting\CashJournalReport;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * «Ταμειακό ημερολόγιο» (#10): customer cash movements for a period, grouped by
 * account → payment method, εισπράξεις (kind=payment) vs πληρωμές/επιστροφές
 * (kind=refund), with subtotals and a reconciling grand total.
 */
class CashJournalReportTest extends TestCase
{
    use RefreshDatabase;

    private Company $tenant;

    private Customer $customer;

    private static int $seq = 0;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-05-15 12:00:00'));

        $this->tenant = Company::create([
            'name' => 'Cash', 'slug' => 'cash-'.uniqid(), 'country_code' => 'GR',
            'einvoice_provider' => 'gr-mydata', 'mydata_mode' => 'off',
        ]);
        $this->customer = Customer::create(['company_id' => $this->tenant->id, 'name' => 'ΠΕΛΑΤΗΣ']);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function method(string $name): PaymentMethod
    {
        return PaymentMethod::create(['company_id' => $this->tenant->id, 'description' => $name]);
    }

    private function account(string $bank): BankAccount
    {
        return BankAccount::create(['company_id' => $this->tenant->id, 'bank_name' => $bank, 'iban' => 'GR'.(++self::$seq)]);
    }

    private function pay(float $amount, string $kind, ?PaymentMethod $method, ?BankAccount $account, ?string $payDate = '2026-05-10', array $overrides = []): Payment
    {
        return Payment::create(array_merge([
            'company_id' => $this->tenant->id,
            'customer_id' => $this->customer->id,
            'kind' => $kind,
            'amount' => $amount,
            'payment_method_id' => $method?->id,
            'bank_account_id' => $account?->id,
            'pay_date' => $payDate,
        ], $overrides));
    }

    private function build(string $from = '2026-05-01', string $to = '2026-05-31'): array
    {
        return app(CashJournalReport::class)->build(
            $this->tenant,
            Carbon::parse($from)->startOfDay(),
            Carbon::parse($to)->endOfDay(),
        );
    }

    public function test_groups_by_account_then_method_with_in_out_net_and_reconciling_totals(): void
    {
        $card = $this->method('Κάρτα');
        $cash = $this->method('Μετρητά');
        $bankA = $this->account('ΑΛΦΑ');
        $bankB = $this->account('ΒΗΤΑ');

        // Bank A · Κάρτα: 100 + 50 in, 30 refund → in 150 / out 30 / net 120 / 3 κιν.
        $this->pay(100, 'payment', $card, $bankA);
        $this->pay(50, 'payment', $card, $bankA);
        $this->pay(30, 'refund', $card, $bankA);
        // Bank A · Μετρητά: 40 in.
        $this->pay(40, 'payment', $cash, $bankA);
        // Χωρίς λογαριασμό (till) · Μετρητά: 20 in.
        $this->pay(20, 'payment', $cash, null);
        // Bank B · Κάρτα: 10 refund → out only.
        $this->pay(10, 'refund', $card, $bankB);

        $r = $this->build();

        // Grand totals: in 210, out 40, net 170, 6 movements.
        $this->assertEqualsWithDelta(210.0, $r['total_in'], 0.01);
        $this->assertEqualsWithDelta(40.0, $r['total_out'], 0.01);
        $this->assertEqualsWithDelta(170.0, $r['total_net'], 0.01);
        $this->assertSame(6, $r['total_count']);

        $byAcc = collect($r['accounts'])->keyBy('account');

        // Bank A subtotal.
        $this->assertEqualsWithDelta(190.0, $byAcc['ΑΛΦΑ — GR1']['in'], 0.01);
        $this->assertEqualsWithDelta(30.0, $byAcc['ΑΛΦΑ — GR1']['out'], 0.01);
        $this->assertEqualsWithDelta(160.0, $byAcc['ΑΛΦΑ — GR1']['net'], 0.01);
        $this->assertSame(4, $byAcc['ΑΛΦΑ — GR1']['count']);

        // Bank A method breakdown: Κάρτα (gross 180) before Μετρητά (gross 40).
        $methods = $byAcc['ΑΛΦΑ — GR1']['methods'];
        $this->assertSame(['Κάρτα', 'Μετρητά'], array_column($methods, 'method'));
        $this->assertEqualsWithDelta(150.0, $methods[0]['in'], 0.01);
        $this->assertEqualsWithDelta(30.0, $methods[0]['out'], 0.01);
        $this->assertEqualsWithDelta(120.0, $methods[0]['net'], 0.01);

        // The no-account bucket resolves + sinks LAST despite outranking Bank B by gross.
        $this->assertSame('Χωρίς λογαριασμό', end($r['accounts'])['account']);
        $this->assertNull(end($r['accounts'])['account_id']);
    }

    public function test_excludes_out_of_period_and_soft_deleted_rows(): void
    {
        $cash = $this->method('Μετρητά');

        $this->pay(100, 'payment', $cash, null, '2026-05-10');          // in-period
        $this->pay(999, 'payment', $cash, null, '2026-04-30');          // before window
        $this->pay(888, 'payment', $cash, null, '2026-06-01');          // after window
        $deleted = $this->pay(500, 'payment', $cash, null, '2026-05-11');
        $deleted->delete();                                             // soft-deleted

        $r = $this->build();

        $this->assertEqualsWithDelta(100.0, $r['total_in'], 0.01);
        $this->assertSame(1, $r['total_count']);
    }

    public function test_soft_deleted_account_still_resolves_its_label(): void
    {
        $card = $this->method('Κάρτα');
        $bank = $this->account('ΓΑΜΜΑ');
        $this->pay(100, 'payment', $card, $bank);

        // Soft-delete the account AFTER the payment (the row + id survive).
        $bank->delete();

        $r = $this->build();

        // The payment still points at the (soft-deleted) account, and withTrashed
        // resolves its real name — NOT «#id» and NOT the no-account bucket.
        $accounts = collect($r['accounts'])->pluck('account')->all();
        $this->assertContains('ΓΑΜΜΑ — GR'.self::$seq, $accounts);
        $this->assertNotContains('Χωρίς λογαριασμό', $accounts);
    }

    public function test_null_amount_legacy_row_is_excluded(): void
    {
        $cash = $this->method('Μετρητά');
        $this->pay(100, 'payment', $cash, null, '2026-05-10');

        // A legacy/ETL anomaly: amount NULL (bypasses the model guard via raw insert).
        // It must not appear as a €0.00 movement inflating «Πλήθος».
        DB::table('payments')->insert([
            'company_id' => $this->tenant->id,
            'customer_id' => $this->customer->id,
            'kind' => 'payment',
            'amount' => null,
            'payment_method_id' => $cash->id,
            'pay_date' => '2026-05-12',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $r = $this->build();

        $this->assertEqualsWithDelta(100.0, $r['total_in'], 0.01);
        $this->assertSame(1, $r['total_count']);
    }

    public function test_null_pay_date_falls_back_to_created_at(): void
    {
        $cash = $this->method('Μετρητά');
        // No pay_date; created_at = test-now (2026-05-15) → inside the May window.
        $this->pay(70, 'payment', $cash, null, null);

        $r = $this->build();

        $this->assertEqualsWithDelta(70.0, $r['total_in'], 0.01);
        $this->assertSame(1, $r['total_count']);
    }
}
