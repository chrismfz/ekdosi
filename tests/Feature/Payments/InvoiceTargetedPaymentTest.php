<?php

namespace Tests\Feature\Payments;

use App\Models\BankAccount;
use App\Models\Company;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\InvoiceType;
use App\Models\Payment;
use App\Models\PaymentGatewayConnection;
use App\Models\PaymentIntent;
use App\Models\PaymentMethod;
use App\Services\InvoiceBalance;
use App\Services\Payments\PaymentIntentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Invoice-TARGETED portal payment: the customer chose ONE invoice, so settle()
 * must pay THAT document (not the FIFO oldest), park any overpayment on-account,
 * and hard-link every resulting Payment back to the intent. Plus the safety
 * fallbacks (target no longer payable → FIFO; a late capture on an auto-expired
 * intent still records — money truth).
 */
class InvoiceTargetedPaymentTest extends TestCase
{
    use RefreshDatabase;

    private Company $t;

    private Customer $customer;

    private int $creditMethodId;

    private int $typeId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->t = Company::create(['name' => 'T', 'slug' => 'it-'.uniqid(), 'country_code' => 'GR']);
        $this->customer = Customer::create(['company_id' => $this->t->id, 'name' => 'C', 'afm' => '090000045']);
        $this->typeId = InvoiceType::create(['company_id' => $this->t->id, 'code' => 'ΤΙΜ', 'name' => 'Τ', 'invcount' => 1, 'mydata_type' => '1.1'])->id;
        $this->creditMethodId = PaymentMethod::create(['company_id' => $this->t->id, 'description' => 'Επί Πιστώσει', 'due_days' => 30])->id;
    }

    private function connection(): PaymentGatewayConnection
    {
        BankAccount::create([
            'company_id' => $this->t->id, 'bank_name' => 'Τράπεζα', 'iban' => 'GR-IBAN-0001',
            'account_name' => 'Δικαιούχος', 'is_active' => true,
        ]);

        return PaymentGatewayConnection::create([
            'company_id' => $this->t->id, 'gateway' => 'manual', 'label' => 'Κατάθεση',
            'is_active' => true, 'sort' => 0, 'config' => ['instructions' => 'ref'],
        ]);
    }

    private function creditInvoice(float $gross, int $daysAgo): Invoice
    {
        $inv = Invoice::create([
            'company_id' => $this->t->id, 'invcode' => 'ΤΙΜ'.random_int(1, 99999), 'code' => random_int(1, 99999),
            'invoice_type_id' => $this->typeId, 'customer_id' => $this->customer->id, 'issued_at' => now()->subDays($daysAgo),
            'local_status' => 'active', 'payment_method_id' => $this->creditMethodId,
        ]);
        $inv->forceFill(['net_total' => $gross, 'gross_total' => $gross])->save();

        return $inv;
    }

    private function service(): PaymentIntentService
    {
        return app(PaymentIntentService::class);
    }

    private function balance(Invoice $inv): float
    {
        return (float) app(InvoiceBalance::class)->for($inv->fresh())->balance;
    }

    public function test_start_with_invoice_target_records_the_target_and_purpose(): void
    {
        $inv = $this->creditInvoice(50, 2);
        $intent = $this->service()->start($this->customer, $this->connection(), 50.0, invoice: $inv)['intent'];

        $this->assertSame($inv->id, $intent->invoice_id);
        $this->assertSame('invoice', $intent->purpose);
    }

    public function test_start_refuses_an_invoice_of_another_customer(): void
    {
        $other = Customer::create(['company_id' => $this->t->id, 'name' => 'Other', 'afm' => '1']);
        $foreign = Invoice::create([
            'company_id' => $this->t->id, 'invcode' => 'X1', 'code' => 1,
            'invoice_type_id' => $this->typeId, 'customer_id' => $other->id, 'issued_at' => now(),
            'local_status' => 'active', 'payment_method_id' => $this->creditMethodId,
        ]);

        $this->expectException(\RuntimeException::class);
        $this->service()->start($this->customer, $this->connection(), 10.0, invoice: $foreign);
    }

    public function test_settle_pays_the_chosen_invoice_not_the_oldest(): void
    {
        $old = $this->creditInvoice(100, 10);   // older, would win FIFO
        $new = $this->creditInvoice(50, 1);      // the one the customer picked

        $intent = $this->service()->start($this->customer, $this->connection(), 50.0, invoice: $new)['intent'];
        $this->service()->settle($intent, 'op@example.com', transactionId: 'txn-1');

        $this->assertSame(0.0, $this->balance($new), 'chosen invoice paid');
        $this->assertSame(100.0, $this->balance($old), 'the oldest was NOT touched');

        // Hard link both ways + the acquirer txn on the row.
        $payment = Payment::where('invoice_id', $new->id)->firstOrFail();
        $this->assertSame($intent->id, $payment->payment_intent_id);
        $this->assertSame('txn-1', $payment->transaction_id);
        $this->assertTrue($intent->fresh()->payments->contains($payment));
        $this->assertSame($intent->id, $payment->paymentIntent->id);
    }

    public function test_overpayment_of_a_targeted_invoice_parks_remainder_on_account(): void
    {
        $inv = $this->creditInvoice(50, 1);
        $intent = $this->service()->start($this->customer, $this->connection(), 80.0, invoice: $inv)['intent'];
        $this->service()->settle($intent, 'op@example.com');

        $this->assertSame(0.0, $this->balance($inv), 'invoice fully paid (capped at €50)');
        // €30 remainder is on-account, and it too is linked to the intent.
        $onAccount = Payment::where('payment_intent_id', $intent->id)->whereNull('invoice_id')->firstOrFail();
        $this->assertSame('30.00', (string) $onAccount->amount);
    }

    public function test_settle_falls_back_to_fifo_when_the_target_is_no_longer_payable(): void
    {
        $open = $this->creditInvoice(100, 10);   // another open invoice
        $target = $this->creditInvoice(50, 1);

        $intent = $this->service()->start($this->customer, $this->connection(), 50.0, invoice: $target)['intent'];
        // The chosen invoice is cancelled AFTER the intent starts but BEFORE settle.
        $target->update(['local_status' => 'cancelled']);

        $this->service()->settle($intent, 'op@example.com');

        // Money must NOT be stranded: it lands on the other open invoice (FIFO), and
        // the intent is settled + the payment is still linked to it.
        $this->assertSame(PaymentIntent::STATUS_SETTLED, $intent->fresh()->status);
        $payment = Payment::where('payment_intent_id', $intent->id)->firstOrFail();
        $this->assertSame($open->id, $payment->invoice_id);
        $this->assertSame(50.0, (float) $payment->amount);
    }

    public function test_a_verified_capture_still_settles_an_auto_expired_intent(): void
    {
        $inv = $this->creditInvoice(50, 1);
        $intent = $this->service()->start($this->customer, $this->connection(), 50.0, invoice: $inv)['intent'];

        // The stale-intent sweep expired it (customer took a while / a late return).
        $intent->forceFill(['status' => PaymentIntent::STATUS_EXPIRED])->save();

        $this->service()->settle($intent, 'webhook:eurobank');

        $this->assertSame(PaymentIntent::STATUS_SETTLED, $intent->fresh()->status, 'money truth overrides the expiry guess');
        $this->assertSame(0.0, $this->balance($inv));
    }
}
