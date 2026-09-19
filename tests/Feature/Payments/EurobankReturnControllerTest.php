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
use App\Models\User;
use App\Services\InvoiceBalance;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
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

    /**
     * The vPOS RETURN digest, mirroring EurobankGateway::returnDigest() byte-for-byte:
     * concat the returned values in received order (minus the browser `_charset_`/
     * `submitButton` artifacts and the `digest` itself), append the secret, hash the
     * RAW bytes — NO iconv (the inbound leg hashes exactly what the bank sent).
     */
    private function sign(array $fields, string $secret): string
    {
        $s = '';
        foreach ($fields as $k => $v) {
            if (in_array($k, ['_charset_', 'digest', 'submitButton'], true)) {
                continue;
            }
            $s .= is_scalar($v) ? (string) $v : '';   // mirror handleWebhook()'s scalar guard
        }

        return base64_encode(hash('sha256', $s.$secret, true));
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
            'mid' => 'MID123', 'orderid' => (string) $intent->id, 'status' => 'CAPTURED',
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

        $fields = ['mid' => 'MID123', 'orderid' => (string) $intent->id, 'status' => 'REFUSED', 'orderAmount' => '100.00', 'currency' => 'EUR'];
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

    public function test_a_captured_return_rings_the_operators_bell(): void
    {
        // An operator of the tenant should get a database (bell) notification when
        // an unattended gateway settlement lands.
        $user = User::create(['name' => 'Op', 'email' => 'op-'.uniqid().'@t.local', 'password' => bcrypt('x')]);
        $user->companies()->attach($this->t->id);

        $this->creditInvoice(100);
        $intent = $this->pendingIntent(100);
        $this->postReturn($this->capturedReturn($intent));

        $this->assertSame(1, DB::table('notifications')
            ->where('notifiable_id', $user->id)->count());
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

    /**
     * A PaymentIntent with a caller-chosen id, so the digest-boundary attack below
     * is deterministic (it needs `mid . payerId` to END with the victim id).
     */
    private function intentWithId(int $id, float $amount = 100.0): PaymentIntent
    {
        DB::table('payment_intents')->insert([
            'id' => $id, 'company_id' => $this->t->id, 'customer_id' => $this->customer->id,
            'gateway' => 'eurobank', 'payment_gateway_connection_id' => $this->conn->id,
            'amount' => $amount, 'currency' => 'EUR', 'purpose' => 'balance',
            'status' => PaymentIntent::STATUS_PENDING, 'reference' => 'ΠΛ-FIX-'.$id,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return PaymentIntent::withoutGlobalScopes()->findOrFail($id);
    }

    /**
     * THE digest-boundary attack. The vPOS digest signs a delimiter-less
     * concatenation of the returned values, so the field BOUNDARIES are not signed.
     * A customer who completed one genuine payment can re-split the SAME bytes
     * across mid|orderid — the digest still verifies — and aim the settlement at
     * someone else's intent. `mid` must be pinned to the connection's terminal.
     */
    public function test_a_return_with_a_repartitioned_mid_boundary_is_refused(): void
    {
        $victim = $this->intentWithId(42);          // 'MID123' . '500042' ends with '42'
        $payer = $this->intentWithId(500042);

        // Genuine, correctly-signed return for the PAYER's own intent.
        $genuine = [
            'mid' => 'MID123', 'orderid' => '500042', 'status' => 'CAPTURED',
            'orderAmount' => '100.00', 'currency' => 'EUR', 'txId' => 'TX-RP',
        ];
        $genuine['digest'] = $this->sign($genuine, self::SECRET);
        $this->postReturn($genuine);
        $this->assertSame(PaymentIntent::STATUS_SETTLED, $payer->fresh()->status);

        // Same bytes, boundary shifted: mid loses its last 4 chars, orderid gains
        // them — concatenation (and therefore the digest) is byte-identical.
        $attack = [
            'mid' => 'MID1235000', 'orderid' => '42', 'status' => 'CAPTURED',
            'orderAmount' => '100.00', 'currency' => 'EUR', 'txId' => 'TX-RP',
        ];
        $attack['digest'] = $genuine['digest'];
        $this->assertSame($this->sign($attack, self::SECRET), $genuine['digest'], 'the attack must carry a VALID digest');

        $this->postReturn($attack);

        // The victim's intent is untouched and no second payment was written.
        $this->assertSame(PaymentIntent::STATUS_PENDING, $victim->fresh()->status);
        $this->assertSame(1, Payment::where('company_id', $this->t->id)->count());
        $this->assertSame(100.0, (float) Payment::where('company_id', $this->t->id)->sum('amount'));

        $event = PaymentGatewayEvent::query()->where('order_id', '42')->firstOrFail();
        $this->assertSame(PaymentGatewayEvent::OUTCOME_REJECTED, $event->outcome);
        $this->assertSame('mid_mismatch', $event->reason);
        // The digest DID verify — that is precisely why the mid guard is needed.
        $this->assertTrue($event->verified);
    }

    /** A return that carries no `mid` at all is the same re-partition — refused. */
    public function test_a_return_without_a_mid_is_refused(): void
    {
        $intent = $this->pendingIntent(100);
        $fields = [
            'orderid' => (string) $intent->id, 'status' => 'CAPTURED',
            'orderAmount' => '100.00', 'currency' => 'EUR', 'txId' => 'TX-NOMID',
        ];
        $fields['digest'] = $this->sign($fields, self::SECRET);

        $this->postReturn($fields);

        $this->assertSame(PaymentIntent::STATUS_PENDING, $intent->fresh()->status);
        $this->assertSame(0, Payment::where('company_id', $this->t->id)->count());
    }

    /**
     * Second, independent guard: one acquirer transaction may settle at most ONE
     * intent, however the return was crafted. Both returns here are perfectly
     * signed AND carry the right mid — only the shared txId stops the second.
     */
    public function test_one_acquirer_transaction_cannot_settle_two_intents(): void
    {
        $first = $this->pendingIntent(100);
        $second = $this->pendingIntent(100);

        $sharedTxn = static function (PaymentIntent $intent): array {
            return [
                'version' => '2', 'mid' => 'MID123', 'orderid' => (string) $intent->id,
                'status' => 'CAPTURED', 'orderAmount' => '100.00', 'currency' => 'EUR',
                'txId' => 'TX-SHARED',
            ];
        };

        $fields = $sharedTxn($first);
        $fields['digest'] = $this->sign($fields, self::SECRET);
        $this->postReturn($fields);
        $this->assertSame(PaymentIntent::STATUS_SETTLED, $first->fresh()->status);

        // Same acquirer transaction, aimed at a second intent — perfectly signed,
        // right mid, right amount. Only the reused txn id stops it.
        $fields = $sharedTxn($second);
        $fields['digest'] = $this->sign($fields, self::SECRET);
        $this->postReturn($fields);

        $this->assertSame(PaymentIntent::STATUS_PENDING, $second->fresh()->status);
        $this->assertSame(1, Payment::where('company_id', $this->t->id)->count());

        $event = PaymentGatewayEvent::query()->where('order_id', (string) $second->id)->firstOrFail();
        $this->assertSame('duplicate_transaction', $event->reason);
    }

    /**
     * The dedup guard must not collide with the FREE-TEXT `transaction_id`
     * namespace. An operator who reconciles a stranded payment from the bank
     * statement and types the acquirer's txn id by hand writes an intent-less
     * Payment with that id; the genuine return arriving afterwards must still
     * settle, not be refused forever.
     */
    public function test_a_manual_payment_sharing_the_txn_id_does_not_block_the_gateway_settle(): void
    {
        $this->creditInvoice(100);
        $intent = $this->pendingIntent(100);

        // Operator-entered payment: same transaction_id, NO payment_intent_id.
        Payment::create([
            'company_id' => $this->t->id,
            'customer_id' => $this->customer->id,
            'amount' => 100.00,
            'pay_date' => now(),
            'transaction_id' => 'TX-MANUAL',
            'notes' => 'Χειροκίνητη καταχώριση από extrait',
        ]);

        $fields = [
            'version' => '2', 'mid' => 'MID123', 'orderid' => (string) $intent->id,
            'status' => 'CAPTURED', 'orderAmount' => '100.00', 'currency' => 'EUR',
            'txId' => 'TX-MANUAL',
        ];
        $fields['digest'] = $this->sign($fields, self::SECRET);

        $this->postReturn($fields);

        $this->assertSame(PaymentIntent::STATUS_SETTLED, $intent->fresh()->status);
        $this->assertSame(1, Payment::where('company_id', $this->t->id)
            ->whereNotNull('payment_intent_id')->count());
    }

    /**
     * The re-partition attack WITHOUT stretching `mid` — the variant that defeats a
     * naive mid pin. The attacker leaves `mid` byte-identical and smuggles the
     * donated bytes in an EXTRA field between `mid` and `orderid`; the concatenation
     * (and therefore the digest) is unchanged. Only canonical reconstruction of the
     * sign-string stops this.
     */
    public function test_a_filler_field_cannot_re_aim_a_genuine_return(): void
    {
        $victim = $this->intentWithId(42);
        $payer = $this->intentWithId(500042);

        $genuine = [
            'mid' => 'MID123', 'orderid' => '500042', 'status' => 'CAPTURED',
            'orderAmount' => '100.00', 'currency' => 'EUR', 'txId' => 'TX-RP',
        ];
        $genuine['digest'] = $this->sign($genuine, self::SECRET);
        $this->postReturn($genuine);
        $this->assertSame(PaymentIntent::STATUS_SETTLED, $payer->fresh()->status);

        // `mid` UNCHANGED. The 4 donated bytes ride in an unknown field, and the
        // transaction id is renamed so the dedup would not even see it.
        $attack = [
            'mid' => 'MID123', 'zz' => '5000', 'orderid' => '42', 'status' => 'CAPTURED',
            'orderAmount' => '100.00', 'currency' => 'EUR', 'yy' => 'TX-RP',
            'digest' => $genuine['digest'],
        ];
        $this->postReturn($attack);

        $this->assertSame(PaymentIntent::STATUS_PENDING, $victim->fresh()->status);
        $this->assertSame(1, Payment::where('company_id', $this->t->id)->count());
        $this->assertSame(100.0, (float) Payment::where('company_id', $this->t->id)->sum('amount'));
    }

    /**
     * Transmission order is NORMALISED away: a body whose known fields arrive
     * shuffled rebuilds to the same canonical sign-string, so it verifies and
     * settles the SAME intent. That is the point of canonical reconstruction — the
     * attacker gains nothing by reordering, because `orderid` is re-anchored between
     * `mid` and `status` no matter where it was posted.
     */
    public function test_a_reordered_return_normalises_to_the_same_message(): void
    {
        $this->creditInvoice(100);
        $intent = $this->pendingIntent(100);
        $canonical = [
            'mid' => 'MID123', 'orderid' => (string) $intent->id, 'status' => 'CAPTURED',
            'orderAmount' => '100.00', 'currency' => 'EUR', 'txId' => 'TX-ORD',
        ];
        $digest = $this->sign($canonical, self::SECRET);

        $this->postReturn(['status' => 'CAPTURED', 'mid' => 'MID123', 'currency' => 'EUR',
            'orderid' => (string) $intent->id, 'txId' => 'TX-ORD', 'orderAmount' => '100.00',
            'digest' => $digest]);

        $this->assertSame(PaymentIntent::STATUS_SETTLED, $intent->fresh()->status);
        $this->assertSame(1, Payment::where('company_id', $this->t->id)->count());
    }

    /**
     * A CAPTURED return reporting no transaction id leaves the dedup inert, but the
     * bank HAS taken the money — so it settles (refusing would strand a real
     * payment, and alternative rails may not report an id) and the operators are
     * alerted instead.
     */
    public function test_a_captured_return_without_a_transaction_id_still_settles(): void
    {
        $this->creditInvoice(100);
        $intent = $this->pendingIntent(100);
        $fields = [
            'mid' => 'MID123', 'orderid' => (string) $intent->id, 'status' => 'CAPTURED',
            'orderAmount' => '100.00', 'currency' => 'EUR',
        ];
        $fields['digest'] = $this->sign($fields, self::SECRET);

        $this->postReturn($fields);

        $this->assertSame(PaymentIntent::STATUS_SETTLED, $intent->fresh()->status);
        $this->assertSame(1, Payment::where('company_id', $this->t->id)->count());
    }

    /**
     * Operator cancels while the customer is still on the hosted page, then the
     * charge goes through: the bank took the money and we write none. That must be
     * raised, not filed as a routine «already settled».
     */
    public function test_a_capture_landing_on_a_cancelled_intent_is_raised(): void
    {
        $this->creditInvoice(100);
        $intent = $this->pendingIntent(100);
        $intent->forceFill(['status' => PaymentIntent::STATUS_CANCELLED])->save();

        $this->postReturn($this->capturedReturn($intent));

        $this->assertSame(PaymentIntent::STATUS_CANCELLED, $intent->fresh()->status);
        $this->assertSame(0, Payment::where('company_id', $this->t->id)->count());

        $event = PaymentGatewayEvent::query()->where('order_id', (string) $intent->id)->firstOrFail();
        $this->assertSame(PaymentGatewayEvent::OUTCOME_REJECTED, $event->outcome);
        $this->assertSame('settle_on_cancelled_intent', $event->reason);
    }

    /**
     * The `cancelUrl` leg posts a different field set, so a routine «Άκυρο» can trip
     * the strict field check. It must be filed as the non-capture it is — never as a
     * forgery attempt, or real cancellations become indistinguishable from attacks.
     */
    public function test_a_cancel_leg_with_unexpected_fields_is_not_filed_as_forgery(): void
    {
        $intent = $this->pendingIntent(100);

        $this->postReturn([
            'mid' => 'MID123', 'orderid' => (string) $intent->id, 'status' => 'CANCELED',
            'authCode' => '', 'responseCode' => 'CANCEL', 'digest' => 'whatever',
        ], result: 'failure');

        $this->assertSame(PaymentIntent::STATUS_PENDING, $intent->fresh()->status);
        $this->assertSame(0, Payment::where('company_id', $this->t->id)->count());

        $event = PaymentGatewayEvent::query()->where('order_id', (string) $intent->id)->firstOrFail();
        $this->assertSame(PaymentGatewayEvent::OUTCOME_IGNORED, $event->outcome);
        $this->assertSame('not_captured', $event->reason);
    }

    /** …but a body CLAIMING a capture with unknown fields is still a hard refusal. */
    public function test_an_unknown_field_on_a_claimed_capture_is_still_refused(): void
    {
        $intent = $this->pendingIntent(100);

        $this->postReturn([
            'mid' => 'MID123', 'orderid' => (string) $intent->id, 'status' => 'CAPTURED',
            'orderAmount' => '100.00', 'currency' => 'EUR', 'txId' => 'TX-U',
            'authCode' => '123', 'digest' => 'whatever',
        ]);

        $this->assertSame(PaymentIntent::STATUS_PENDING, $intent->fresh()->status);
        $event = PaymentGatewayEvent::query()->where('order_id', (string) $intent->id)->firstOrFail();
        $this->assertSame(PaymentGatewayEvent::OUTCOME_REJECTED, $event->outcome);
        $this->assertSame('digest_verification_failed', $event->reason);
    }
}
