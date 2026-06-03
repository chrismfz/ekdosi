<?php

namespace Tests\Feature\Payments;

use App\Enums\PaymentStatus;
use App\Models\Company;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\InvoiceType;
use App\Models\Payment;
use App\Models\PaymentMethod;
use App\Services\CustomerLedger\CustomerLedgerBuilder;
use App\Services\Dashboard\DashboardMetrics;
use App\Services\InvoiceBalance;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * #3 — refunds (kind='refund'): money OUT, stored positive, but SUBTRACTED from
 * paid everywhere (InvoiceBalance, καρτέλα, dashboard receivables, customer
 * outstanding-balance) so all surfaces agree.
 */
class RefundTest extends TestCase
{
    use RefreshDatabase;

    private Company $tenant;

    private Customer $customer;

    private int $creditMethodId;

    private int $typeId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Company::create(['name' => 'Ref', 'slug' => 'ref-'.uniqid(), 'country_code' => 'GR']);
        $this->customer = Customer::create(['company_id' => $this->tenant->id, 'name' => 'Π', 'afm' => '123456789']);
        $this->typeId = InvoiceType::create(['company_id' => $this->tenant->id, 'code' => 'ΤΙΜ', 'name' => 'Τ', 'invcount' => 1, 'mydata_type' => '1.1'])->id;
        $this->creditMethodId = PaymentMethod::create(['company_id' => $this->tenant->id, 'description' => 'Επί Πιστώσει', 'due_days' => 30])->id;
    }

    private function invoice(float $gross = 100): Invoice
    {
        $inv = Invoice::create([
            'company_id' => $this->tenant->id, 'invcode' => 'ΤΙΜ'.random_int(1, 9999), 'code' => random_int(1, 99999),
            'invoice_type_id' => $this->typeId, 'customer_id' => $this->customer->id, 'issued_at' => now()->subDays(5),
            'local_status' => 'active', 'payment_method_id' => $this->creditMethodId,
        ]);
        $inv->forceFill(['net_total' => $gross, 'gross_total' => $gross])->save();

        return $inv;
    }

    private function pay(Invoice $inv, float $amount, string $kind = 'payment'): Payment
    {
        return Payment::create([
            'company_id' => $this->tenant->id, 'customer_id' => $this->customer->id,
            'invoice_id' => $inv->id, 'kind' => $kind, 'amount' => $amount, 'pay_date' => now(),
        ]);
    }

    public function test_refund_reduces_invoice_paid_total_and_reopens_balance(): void
    {
        $inv = $this->invoice(100);
        $this->pay($inv, 100);                  // fully paid
        $this->assertSame(PaymentStatus::Paid, app(InvoiceBalance::class)->for($inv->fresh())->status);

        $this->pay($inv, 40, 'refund');         // give 40 back

        $bal = app(InvoiceBalance::class)->for($inv->fresh());
        $this->assertSame(60.0, (float) $bal->paid, 'paid = 100 − 40');
        $this->assertSame(40.0, (float) $bal->balance, 'balance reopens by the refund');
        $this->assertSame(PaymentStatus::Partial, $bal->status);
    }

    public function test_refund_nets_out_in_dashboard_receivables_and_customer_balance(): void
    {
        $inv = $this->invoice(100);
        $this->pay($inv, 100);
        // receivable 0 after full payment
        $this->assertSame(0.0, app(DashboardMetrics::class, ['tenant' => $this->tenant])->outstandingReceivables());

        $this->pay($inv, 30, 'refund');
        // refund raises receivable back to 30
        $this->assertSame(30.0, app(DashboardMetrics::class, ['tenant' => $this->tenant])->outstandingReceivables());

        $row = Customer::query()->withOutstandingBalance($this->tenant->id)->whereKey($this->customer->id)->first();
        $this->assertSame(30.0, round((float) $row->outstanding_balance, 2));
    }

    public function test_cash_term_invoice_has_no_real_paid_so_no_phantom_refund(): void
    {
        // A cash-term invoice (due_days 0) is settled-at-issue: paid=owed
        // synthetically, but there are NO real payment rows. The cockpit's
        // paidSoFar() must read the rows (→ 0), so a refund is never offered
        // and no phantom receivable can be created.
        $cash = PaymentMethod::create(['company_id' => $this->tenant->id, 'description' => 'Μετρητά', 'due_days' => 0]);
        $inv = Invoice::create([
            'company_id' => $this->tenant->id, 'invcode' => 'ΑΠΥ1', 'code' => 1,
            'invoice_type_id' => $this->typeId, 'customer_id' => $this->customer->id, 'issued_at' => now(),
            'local_status' => 'active', 'payment_method_id' => $cash->id,
        ]);
        $inv->forceFill(['net_total' => 100, 'gross_total' => 100])->save();

        // synthetic paid = owed = 100 …
        $this->assertSame(100.0, (float) app(InvoiceBalance::class)->for($inv)->paid);
        // … but no real payment rows behind it.
        $realPaid = (float) Payment::where('invoice_id', $inv->id)
            ->selectRaw('COALESCE(SUM('.Payment::NET_AMOUNT_SQL.'), 0) AS n')->value('n');
        $this->assertSame(0.0, $realPaid, 'no real payments → refund action hidden, no phantom receivable');
    }

    public function test_refund_appears_as_debit_in_customer_ledger(): void
    {
        $inv = $this->invoice(100);
        $this->pay($inv, 100);
        $this->pay($inv, 25, 'refund');

        $result = app(CustomerLedgerBuilder::class)->build($this->customer);
        $refundRows = array_values(array_filter($result->ledger, fn ($e) => $e['type'] === 'refund'));

        $this->assertCount(1, $refundRows);
        $this->assertSame(25.0, (float) $refundRows[0]['debit']);
        $this->assertSame(0.0, (float) $refundRows[0]['credit']);
        // ΤΙΜ debit 100 (credit-term) − payment 100 + refund 25 = 25 owed.
        $this->assertSame(25.0, round((float) $result->stats['balance'], 2));
    }
}
