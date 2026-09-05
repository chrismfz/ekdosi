<?php

namespace Tests\Feature\Payments;

use App\Models\Company;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\InvoiceType;
use App\Models\Payment;
use App\Models\PaymentGatewayConnection;
use App\Models\PaymentGatewayEvent;
use App\Models\PaymentIntent;
use App\Models\PaymentMethod;
use App\Services\InvoiceBalance;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The vPOS return endpoint (B1): the signed, CSRF-exempt POST that settles a
 * pending intent — but ONLY on a verified digest + a matching CAPTURED status +
 * amount/currency/company (T1/T3/T6), and settling exactly once on replay (T2).
 * The browser never settles: a forged/mismatched return leaves the intent pending
 * and writes no money.
 */
class EurobankReturnControllerTest extends TestCase
{
    use RefreshDatabase;

    private const SECRET = 'shared-secret-XYZ';

    private Company $t;

    private Customer $customer;

    private PaymentGatewayConnection $conn;

    protected function setUp(): void
    {
        parent::setUp();
        $this->t = Company::create(['name' => 'T', 'slug' => 'ebr-'.uniqid(), 'country_code' => 'GR']);
        $this->customer = Customer::create(['company_id' => $this->t->id, 'name' => 'C', 'afm' => '090000045']);
        $this->conn = PaymentGatewayConnection::create([
            'company_id' => $this->t->id, 'gateway' => 'eurobank', 'label' => 'Κάρτα',
            'is_active' => true, 'sort' => 0,
            'config' => ['merchant_id' => 'MID123', 'shared_secret' => self::SECRET, 'testmode' => true],
        ]);
    }

    private function creditInvoice(float $gross): Invoice
    {
        $typeId = InvoiceType::create(['company_id' => $this->t->id, 'code' => 'ΤΙΜ', 'name' => 'Τ', 'invcount' => 1, 'mydata_type' => '1.1'])->id;
        $methodId = PaymentMethod::create(['company_id' => $this->t->id, 'description' => 'Επί Πιστώσει', 'due_days' => 30])->id;

        $inv = Invoice::create([
            'company_id' => $this->t->id, 'invcode' => 'ΤΙΜ'.random_int(1, 9999), 'code' => random_int(1, 99999),
            'invoice_type_id' => $typeId, 'customer_id' => $this->customer->id, 'issued_at' => now()->subDays(3),
            'local_status' => 'active', 'payment_method_id' => $methodId,
        ]);
        $inv->forceFill(['net_total' => $gross, 'gross_total' => $gross])->save();

        return $inv;
    }

    private function pendingIntent(float $amount = 100.0): PaymentIntent
    {
        return PaymentIntent::create([
            'company_id' => $this->t->id, 'customer_id' => $this->customer->id, 'gateway' => 'eurobank',
            'payment_gateway_connection_id' => $this->conn->id, 'amount' => $amount, 'currency' => 'EUR',
            'status' => PaymentIntent::STATUS_PENDING, 'reference' => 'ΠΛ-EBR-'.random_int(1000, 9999),
        ]);
    }

    private function sign(array $fields, string $secret): string
    {
        $s = '';
        foreach ($fields as $k => $v) {
            if (in_array($k, ['_charset_', 'digest', 'submitButton'], true)) {
                continue;
            }
            $s .= (string) $v;
        }
        $norm = iconv('utf-8', 'utf-8//IGNORE', $s.$secret);

        return base64_encode(hash('sha256', $norm === false ? $s.$secret : $norm, true));
    }

    /** POST a raw urlencoded return body (order preserved for the digest). */
    private function postReturn(array $fields, string $result = 'success')
    {
        return $this->call(
            'POST',
            route('webhooks.payments.eurobank.return').'?result='.$result,
            [], [], [],
            ['CONTENT_TYPE' => 'application/x-www-form-urlencoded'],
            http_build_query($fields),
        );
    }

    private function capturedReturn(PaymentIntent $intent, string $amount = '100.00', string $secret = self::SECRET): array
    {
        $fields = [
            'version' => '2', 'mid' => 'MID123', 'orderid' => (string) $intent->id,
            'status' => 'CAPTURED', 'orderAmount' => $amount, 'currency' => 'EUR',
            'txId' => 'TX-'.random_int(1, 9999), '_charset_' => 'UTF-8',
        ];
        $fields['digest'] = $this->sign($fields, $secret);

        return $fields;
    }

    public function test_a_verified_captured_return_settles_the_intent_and_drops_the_balance(): void
    {
        $inv = $this->creditInvoice(100);
        $intent = $this->pendingIntent(100);

        $res = $this->postReturn($this->capturedReturn($intent));
        $res->assertRedirect(route('portal.payment.show', $intent->id));

        $this->assertSame(PaymentIntent::STATUS_SETTLED, $intent->fresh()->status);
        $this->assertSame(0.0, (float) app(InvoiceBalance::class)->for($inv->fresh())->balance);
        $this->assertSame(1, Payment::where('customer_id', $this->customer->id)->count());
    }

    public function test_the_acquirer_txn_id_is_recorded_on_the_payment(): void
    {
        $this->creditInvoice(100);
        $intent = $this->pendingIntent(100);
        $fields = $this->capturedReturn($intent);   // carries a txId

        $this->postReturn($fields);

        $payment = Payment::where('customer_id', $this->customer->id)->firstOrFail();
        $this->assertSame($fields['txId'], $payment->transaction_id);
        $this->assertStringContainsString($fields['txId'], (string) $payment->notes);
        $this->assertStringContainsString('eurobank', (string) $payment->notes);
        // The receipt key stays our own ΠΛ- reference (groups the είσπραξη).
        $this->assertSame($intent->reference, $payment->reference);
    }

    public function test_numeric_iso_currency_978_still_settles(): void
    {
        $this->creditInvoice(100);
        $intent = $this->pendingIntent(100);

        // Acquirer echoes EUR as the ISO-4217 numeric '978' — must not be rejected.
        $fields = [
            'orderid' => (string) $intent->id, 'status' => 'CAPTURED',
            'orderAmount' => '100.00', 'currency' => '978', 'txId' => 'TX-978',
        ];
        $fields['digest'] = $this->sign($fields, self::SECRET);

        $this->postReturn($fields);
        $this->assertSame(PaymentIntent::STATUS_SETTLED, $intent->fresh()->status);
    }

    public function test_a_soft_deleted_connection_still_settles_an_in_flight_capture(): void
    {
        $this->creditInvoice(100);
        $intent = $this->pendingIntent(100);
        $fields = $this->capturedReturn($intent);

        // Operator soft-deletes the method mid-payment — the money that arrived must
        // still settle (the secret is resolved withTrashed).
        $this->conn->delete();

        $this->postReturn($fields);
        $this->assertSame(PaymentIntent::STATUS_SETTLED, $intent->fresh()->status);
        $this->assertSame(1, Payment::where('customer_id', $this->customer->id)->count());
    }

    public function test_replayed_return_settles_only_once(): void
    {
        $this->creditInvoice(100);
        $intent = $this->pendingIntent(100);
        $fields = $this->capturedReturn($intent);

        $this->postReturn($fields);
        $this->postReturn($fields);   // replay (vPOS retry / double redirect)

        $this->assertSame(1, Payment::where('customer_id', $this->customer->id)->count());
    }

    public function test_a_forged_digest_never_settles(): void
    {
        $this->creditInvoice(100);
        $intent = $this->pendingIntent(100);

        $fields = $this->capturedReturn($intent, secret: 'attacker-secret');   // wrong secret
        $this->postReturn($fields)->assertRedirect(route('portal.payment.show', $intent->id));

        $this->assertSame(PaymentIntent::STATUS_PENDING, $intent->fresh()->status);
        $this->assertSame(0, Payment::where('customer_id', $this->customer->id)->count());
    }

    public function test_an_amount_that_differs_from_the_intent_never_settles(): void
    {
        $this->creditInvoice(100);
        $intent = $this->pendingIntent(100);

        // Digest is VALID over orderAmount=1.00, but the intent is 100 → T3 reject.
        $fields = $this->capturedReturn($intent, amount: '1.00');
        $this->postReturn($fields);

        $this->assertSame(PaymentIntent::STATUS_PENDING, $intent->fresh()->status);
        $this->assertSame(0, Payment::where('customer_id', $this->customer->id)->count());
    }

    public function test_a_non_captured_status_never_settles(): void
    {
        $this->creditInvoice(100);
        $intent = $this->pendingIntent(100);

        $fields = ['orderid' => (string) $intent->id, 'status' => 'REFUSED', 'orderAmount' => '100.00', 'currency' => 'EUR'];
        $fields['digest'] = $this->sign($fields, self::SECRET);
        $this->postReturn($fields, result: 'failure');

        $this->assertSame(PaymentIntent::STATUS_PENDING, $intent->fresh()->status);
        $this->assertSame(0, Payment::where('customer_id', $this->customer->id)->count());
    }

    public function test_unknown_orderid_redirects_home_without_error(): void
    {
        $this->postReturn(['orderid' => '999999', 'status' => 'CAPTURED', 'digest' => 'x'])
            ->assertRedirect(route('portal.home'));
    }

    public function test_a_captured_return_writes_a_settled_log_event(): void
    {
        $this->creditInvoice(100);
        $intent = $this->pendingIntent(100);
        $fields = $this->capturedReturn($intent);

        $this->postReturn($fields);

        $event = PaymentGatewayEvent::query()->where('order_id', (string) $intent->id)->firstOrFail();
        $this->assertSame(PaymentGatewayEvent::OUTCOME_SETTLED, $event->outcome);
        $this->assertTrue($event->verified);
        $this->assertSame('CAPTURED', $event->provider_status);
        $this->assertSame($fields['txId'], $event->transaction_id);
        $this->assertSame($this->t->id, $event->company_id);
    }

    public function test_a_forged_digest_writes_a_rejected_log_event(): void
    {
        $this->creditInvoice(100);
        $intent = $this->pendingIntent(100);

        $this->postReturn($this->capturedReturn($intent, secret: 'attacker-secret'));

        $event = PaymentGatewayEvent::query()->where('order_id', (string) $intent->id)->firstOrFail();
        $this->assertSame(PaymentGatewayEvent::OUTCOME_REJECTED, $event->outcome);
        $this->assertSame('digest_verification_failed', $event->reason);
        $this->assertFalse($event->verified);
    }

    public function test_an_unknown_orderid_writes_a_rejected_log_event(): void
    {
        $this->postReturn(['orderid' => '999999', 'status' => 'CAPTURED', 'digest' => 'x']);

        $event = PaymentGatewayEvent::query()->where('order_id', '999999')->firstOrFail();
        $this->assertSame(PaymentGatewayEvent::OUTCOME_REJECTED, $event->outcome);
        $this->assertSame('intent_not_found', $event->reason);
    }

    public function test_a_replayed_capture_logs_an_ignored_event_not_a_second_settled(): void
    {
        $this->creditInvoice(100);
        $intent = $this->pendingIntent(100);
        $fields = $this->capturedReturn($intent);

        $this->postReturn($fields);   // 1st → settled
        $this->postReturn($fields);   // replay → no money, must NOT log a 2nd «settled»

        $events = PaymentGatewayEvent::query()->where('order_id', (string) $intent->id)->get();
        $this->assertSame(1, $events->where('outcome', PaymentGatewayEvent::OUTCOME_SETTLED)->count());
        $ignored = $events->firstWhere('outcome', PaymentGatewayEvent::OUTCOME_IGNORED);
        $this->assertNotNull($ignored, 'the replay is logged as ignored');
        $this->assertSame('already_settled', $ignored->reason);
    }

    public function test_a_gateway_payment_inherits_the_connections_mydata_method(): void
    {
        // Operator maps this channel → «Ηλεκτρονικά μέσα Πληρωμών».
        $electronic = PaymentMethod::create(['company_id' => $this->t->id, 'description' => 'Ηλεκτρονικά μέσα Πληρωμών', 'due_days' => 0]);
        $this->conn->update(['payment_method_id' => $electronic->id]);

        $this->creditInvoice(100);
        $intent = $this->pendingIntent(100);
        $this->postReturn($this->capturedReturn($intent));

        $payment = Payment::where('customer_id', $this->customer->id)->firstOrFail();
        $this->assertSame($electronic->id, $payment->payment_method_id, 'the Payment auto-gets the channel method');
    }
}
