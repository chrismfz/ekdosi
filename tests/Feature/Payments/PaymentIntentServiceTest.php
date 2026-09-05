<?php

namespace Tests\Feature\Payments;

use App\Enums\PaymentStatus;
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
use RuntimeException;
use Tests\TestCase;

/**
 * The B0b money path: starting an intent, and settling it into a real Payment via
 * PaymentAllocator (so the balance drops), IDEMPOTENTLY — a double confirmation
 * can never create two payments.
 */
class PaymentIntentServiceTest extends TestCase
{
    use RefreshDatabase;

    private Company $t;

    private Customer $customer;

    private int $creditMethodId;

    private int $typeId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->t = Company::create(['name' => 'T', 'slug' => 'pi-'.uniqid(), 'country_code' => 'GR']);
        $this->customer = Customer::create(['company_id' => $this->t->id, 'name' => 'C', 'afm' => '090000045']);
        $this->typeId = InvoiceType::create(['company_id' => $this->t->id, 'code' => 'ΤΙΜ', 'name' => 'Τ', 'invcount' => 1, 'mydata_type' => '1.1'])->id;
        $this->creditMethodId = PaymentMethod::create(['company_id' => $this->t->id, 'description' => 'Επί Πιστώσει', 'due_days' => 30])->id;
    }

    private function connection(bool $active = true): PaymentGatewayConnection
    {
        // The manual gateway surfaces the tenant's bank accounts — create one.
        BankAccount::create([
            'company_id' => $this->t->id, 'bank_name' => 'Τράπεζα', 'iban' => 'GR-IBAN-0001',
            'account_name' => 'Δικαιούχος', 'is_active' => true,
        ]);

        return PaymentGatewayConnection::create([
            'company_id' => $this->t->id, 'gateway' => 'manual', 'label' => 'Κατάθεση',
            'is_active' => $active, 'sort' => 0,
            // Empty bank_account_ids → all active accounts are shown.
            'config' => ['instructions' => 'ref = αριθμός'],
        ]);
    }

    private function creditInvoice(float $gross): Invoice
    {
        $inv = Invoice::create([
            'company_id' => $this->t->id, 'invcode' => 'ΤΙΜ'.random_int(1, 9999), 'code' => random_int(1, 99999),
            'invoice_type_id' => $this->typeId, 'customer_id' => $this->customer->id, 'issued_at' => now()->subDays(3),
            'local_status' => 'active', 'payment_method_id' => $this->creditMethodId,
        ]);
        $inv->forceFill(['net_total' => $gross, 'gross_total' => $gross])->save();

        return $inv;
    }

    private function service(): PaymentIntentService
    {
        return app(PaymentIntentService::class);
    }

    public function test_start_creates_a_pending_intent_with_offline_instructions(): void
    {
        $result = $this->service()->start($this->customer, $this->connection(), 120.0);

        $intent = $result['intent'];
        $this->assertSame(PaymentIntent::STATUS_PENDING, $intent->status);
        $this->assertSame('120.00', (string) $intent->amount);
        $this->assertNotEmpty($intent->reference);
        $this->assertStringContainsString('GR-IBAN-0001', (string) $intent->fresh()->instructions);
        $this->assertSame('offline', $result['initiation']->flow);
    }

    public function test_start_refuses_an_inactive_method(): void
    {
        $this->expectException(RuntimeException::class);
        $this->service()->start($this->customer, $this->connection(active: false), 10.0);
    }

    public function test_settle_writes_a_payment_and_drops_the_balance(): void
    {
        $inv = $this->creditInvoice(100);
        $this->assertSame(100.0, (float) app(InvoiceBalance::class)->for($inv->fresh())->balance);

        $intent = $this->service()->start($this->customer, $this->connection(), 100.0)['intent'];
        $this->service()->settle($intent, 'op@example.com');

        $this->assertSame(PaymentIntent::STATUS_SETTLED, $intent->fresh()->status);
        $this->assertSame(0.0, (float) app(InvoiceBalance::class)->for($inv->fresh())->balance);
        $this->assertSame(PaymentStatus::Paid, app(InvoiceBalance::class)->for($inv->fresh())->status);
    }

    public function test_settle_is_idempotent(): void
    {
        $this->creditInvoice(100);
        $intent = $this->service()->start($this->customer, $this->connection(), 100.0)['intent'];

        $this->service()->settle($intent, 'op@example.com');
        $countAfterFirst = Payment::where('customer_id', $this->customer->id)->count();

        // A second confirmation (double-click / replay) must NOT create a 2nd payment.
        $this->service()->settle($intent, 'op@example.com');
        $this->assertSame($countAfterFirst, Payment::where('customer_id', $this->customer->id)->count());
    }

    public function test_settle_uses_the_actual_amount_received(): void
    {
        $inv = $this->creditInvoice(100);
        $intent = $this->service()->start($this->customer, $this->connection(), 100.0)['intent'];

        // Customer intended 100 but only 60 arrived.
        $this->service()->settle($intent, 'op@example.com', actualAmount: 60.0);

        $this->assertSame(60.0, (float) app(InvoiceBalance::class)->for($inv->fresh())->paid);
        $this->assertSame(40.0, (float) app(InvoiceBalance::class)->for($inv->fresh())->balance);
    }
}
