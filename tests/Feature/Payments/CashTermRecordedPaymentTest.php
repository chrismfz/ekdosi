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
 * Plan B — cash-term invoices stay "settled at issue" UNTIL the operator
 * records a real payment (the money-trail exception). Then they're tracked
 * from the real rows, EVERYWHERE consistently, netting to zero — no phantom.
 */
class CashTermRecordedPaymentTest extends TestCase
{
    use RefreshDatabase;

    private Company $tenant;

    private Customer $customer;

    private int $cashMethodId;

    private int $typeId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Company::create(['name' => 'CT', 'slug' => 'ct-'.uniqid(), 'country_code' => 'GR']);
        $this->customer = Customer::create(['company_id' => $this->tenant->id, 'name' => 'Π', 'afm' => '123456789']);
        $this->typeId = InvoiceType::create(['company_id' => $this->tenant->id, 'code' => 'ΑΠΥ', 'name' => 'Α', 'invcount' => 1, 'mydata_type' => '11.1'])->id;
        $this->cashMethodId = PaymentMethod::create(['company_id' => $this->tenant->id, 'description' => 'Ηλεκτρονικά', 'due_days' => 0])->id;
    }

    private function invoice(float $gross = 19): Invoice
    {
        $inv = Invoice::create([
            'company_id' => $this->tenant->id, 'invcode' => 'ΑΠΥ'.random_int(1, 9999), 'code' => random_int(1, 99999),
            'invoice_type_id' => $this->typeId, 'customer_id' => $this->customer->id, 'issued_at' => now()->subDay(),
            'local_status' => 'active', 'payment_method_id' => $this->cashMethodId,
        ]);
        $inv->forceFill(['net_total' => $gross, 'gross_total' => $gross])->save();

        return $inv;
    }

    private function pay(Invoice $inv, float $amount): Payment
    {
        return Payment::create([
            'company_id' => $this->tenant->id, 'customer_id' => $this->customer->id,
            'invoice_id' => $inv->id, 'kind' => 'payment', 'amount' => $amount, 'pay_date' => now(),
        ]);
    }

    private function dashboard(): float
    {
        return app(DashboardMetrics::class, ['tenant' => $this->tenant])->outstandingReceivables();
    }

    private function customerBalance(): float
    {
        return round((float) Customer::query()->withOutstandingBalance($this->tenant->id)
            ->whereKey($this->customer->id)->first()->outstanding_balance, 2);
    }

    private function ledgerBalance(): float
    {
        return round((float) app(CustomerLedgerBuilder::class)->build($this->customer)->stats['balance'], 2);
    }

    public function test_cash_term_without_payments_is_settled_at_issue(): void
    {
        $inv = $this->invoice(19);
        $data = app(InvoiceBalance::class)->for($inv);

        $this->assertSame(PaymentStatus::Paid, $data->status);
        $this->assertSame(0.0, (float) $data->balance);
        $this->assertSame(19.0, (float) $data->paid, 'synthetic settled-at-issue');
        // Not a receivable anywhere.
        $this->assertSame(0.0, $this->dashboard());
        $this->assertSame(0.0, $this->customerBalance());
        $this->assertSame(0.0, $this->ledgerBalance());
    }

    public function test_full_recorded_payment_is_tracked_and_nets_to_zero(): void
    {
        $inv = $this->invoice(19);
        $this->pay($inv, 19);

        $data = app(InvoiceBalance::class)->for($inv->fresh());
        $this->assertSame(PaymentStatus::Paid, $data->status);
        $this->assertSame(0.0, (float) $data->balance);
        $this->assertSame(19.0, (float) $data->paid, 'REAL recorded payment now');

        // Still net-zero across every surface (no phantom credit/receivable).
        $this->assertSame(0.0, $this->dashboard());
        $this->assertSame(0.0, $this->customerBalance());
        $this->assertSame(0.0, $this->ledgerBalance());
    }

    public function test_partial_recorded_payment_reflects_consistently_everywhere(): void
    {
        $inv = $this->invoice(19);
        $this->pay($inv, 10); // operator logged only €10

        $data = app(InvoiceBalance::class)->for($inv->fresh());
        $this->assertSame(PaymentStatus::Partial, $data->status);
        $this->assertSame(9.0, (float) $data->balance);

        // €9 outstanding shows up IDENTICALLY on dashboard, customer balance and ledger.
        $this->assertSame(9.0, $this->dashboard());
        $this->assertSame(9.0, $this->customerBalance());
        $this->assertSame(9.0, $this->ledgerBalance());
    }

    public function test_deleting_the_payment_reverts_to_settled_at_issue(): void
    {
        $inv = $this->invoice(19);
        $p = $this->pay($inv, 10);
        $this->assertSame(9.0, $this->dashboard());

        $p->delete(); // mark-unpaid / mis-key correction

        $this->assertSame(PaymentStatus::Paid, app(InvoiceBalance::class)->for($inv->fresh())->status);
        $this->assertSame(0.0, $this->dashboard(), 'back to settled-at-issue, no receivable');
        $this->assertSame(0.0, $this->ledgerBalance());
    }
}
