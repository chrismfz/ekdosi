<?php

namespace Tests\Feature\Payments;

use App\Models\Company;
use App\Models\Customer;
use App\Models\PaymentGatewayConnection;
use App\Models\PaymentIntent;
use App\Services\Payments\Gateways\EurobankGateway;
use App\Support\Payments\PaymentOutcome;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Tests\TestCase;

/**
 * The Eurobank/Cardlink vPOS adapter (B1): the SIGNED redirect form it builds and
 * the digest verification on the return. The digest is
 * base64(sha256(concat-of-values . sharedSecret)) — the same scheme both legs, so
 * a self-computed return either verifies (right secret) or fails (wrong secret /
 * tampering), fail-closed.
 */
class EurobankGatewayTest extends TestCase
{
    use RefreshDatabase;

    private const SECRET = 'shared-secret-XYZ';

    private function gateway(): EurobankGateway
    {
        return new EurobankGateway;
    }

    private function connection(string $secret = self::SECRET, bool $test = true): PaymentGatewayConnection
    {
        $t = Company::create(['name' => 'T', 'slug' => 'eb-'.uniqid(), 'country_code' => 'GR']);

        return PaymentGatewayConnection::create([
            'company_id' => $t->id, 'gateway' => 'eurobank', 'label' => 'Κάρτα',
            'is_active' => true, 'sort' => 0,
            'config' => ['merchant_id' => 'MID123', 'shared_secret' => $secret, 'lang' => 'el', 'testmode' => $test],
        ]);
    }

    private function intent(PaymentGatewayConnection $conn, float $amount = 100.0): PaymentIntent
    {
        $customer = Customer::create(['company_id' => $conn->company_id, 'name' => 'C', 'afm' => '090000045', 'email' => 'c@example.com']);

        return PaymentIntent::create([
            'company_id' => $conn->company_id, 'customer_id' => $customer->id, 'gateway' => 'eurobank',
            'payment_gateway_connection_id' => $conn->id, 'amount' => $amount, 'currency' => 'EUR',
            'status' => PaymentIntent::STATUS_PENDING, 'reference' => 'ΠΛ-TEST-'.random_int(1000, 9999),
        ]);
    }

    /** Compute the vPOS digest exactly like the gateway (values in order + secret). */
    private function sign(array $orderedFields, string $secret): string
    {
        $s = '';
        foreach ($orderedFields as $k => $v) {
            if (in_array($k, ['_charset_', 'digest', 'submitButton'], true)) {
                continue;
            }
            $s .= (string) $v;
        }
        $norm = iconv('utf-8', 'utf-8//IGNORE', $s.$secret);

        return base64_encode(hash('sha256', $norm === false ? $s.$secret : $norm, true));
    }

    private function returnRequest(array $orderedFields): Request
    {
        // Raw body preserves order; the controller/gateway parse it with parse_str.
        return Request::create('/webhooks/payments/eurobank/return', 'POST', [], [], [], [
            'CONTENT_TYPE' => 'application/x-www-form-urlencoded',
        ], http_build_query($orderedFields));
    }

    public function test_redirect_form_is_signed_and_points_at_the_test_endpoint(): void
    {
        $conn = $this->connection();
        $intent = $this->intent($conn, 42.5);

        $form = $this->gateway()->redirectForm($intent, $conn);

        $this->assertStringContainsString('cardlink.gr', $form->action);   // test endpoint
        $this->assertSame((string) $intent->id, $form->fields['orderid']);
        $this->assertSame('42.50', $form->fields['orderAmount']);
        $this->assertSame('MID123', $form->fields['mid']);
        $this->assertArrayHasKey('digest', $form->fields);

        // The digest must equal a recomputation over the same ordered values.
        $expected = $this->sign(
            collect($form->fields)->except('digest')->all(),
            self::SECRET,
        );
        $this->assertSame($expected, $form->fields['digest']);
    }

    public function test_live_endpoint_when_testmode_off(): void
    {
        $conn = $this->connection(test: false);
        $form = $this->gateway()->redirectForm($this->intent($conn), $conn);
        $this->assertStringContainsString('vpos.eurocommerce.gr', $form->action);
    }

    public function test_handle_webhook_verifies_a_correct_digest(): void
    {
        $conn = $this->connection();
        $intent = $this->intent($conn);

        $fields = [
            'version' => '2', 'mid' => 'MID123', 'orderid' => (string) $intent->id,
            'status' => 'CAPTURED', 'orderAmount' => '100.00', 'currency' => 'EUR',
            'txId' => 'TX-1', 'message' => 'OK', '_charset_' => 'UTF-8',
        ];
        $fields['digest'] = $this->sign($fields, self::SECRET);

        $outcome = $this->gateway()->handleWebhook($this->returnRequest($fields), $conn);

        $this->assertTrue($outcome->verified);
        $this->assertTrue($outcome->isSettled());
        $this->assertSame((string) $intent->id, $outcome->reference);
        $this->assertSame(100.0, $outcome->amount);
        $this->assertSame('TX-1', $outcome->providerTxnId);
    }

    public function test_handle_webhook_rejects_a_wrong_secret(): void
    {
        $conn = $this->connection();
        $intent = $this->intent($conn);

        $fields = ['orderid' => (string) $intent->id, 'status' => 'CAPTURED', 'orderAmount' => '100.00', 'currency' => 'EUR'];
        // Signed with a DIFFERENT secret → digest won't match the connection's.
        $fields['digest'] = $this->sign($fields, 'attacker-secret');

        $outcome = $this->gateway()->handleWebhook($this->returnRequest($fields), $conn);

        $this->assertFalse($outcome->verified);
        $this->assertFalse($outcome->isSettled());
    }

    public function test_handle_webhook_rejects_a_tampered_amount(): void
    {
        $conn = $this->connection();
        $intent = $this->intent($conn);

        $fields = ['orderid' => (string) $intent->id, 'status' => 'CAPTURED', 'orderAmount' => '100.00', 'currency' => 'EUR'];
        $fields['digest'] = $this->sign($fields, self::SECRET);
        // Attacker bumps the amount AFTER signing → digest no longer matches.
        $fields['orderAmount'] = '1.00';

        $outcome = $this->gateway()->handleWebhook($this->returnRequest($fields), $conn);
        $this->assertFalse($outcome->verified);
    }

    public function test_captured_is_the_only_settled_status(): void
    {
        $conn = $this->connection();
        $intent = $this->intent($conn);

        foreach (['REFUSED' => PaymentOutcome::STATUS_FAILED, 'CANCELED' => PaymentOutcome::STATUS_CANCELLED, 'AUTHORIZED' => PaymentOutcome::STATUS_PENDING] as $vpos => $expected) {
            $fields = ['orderid' => (string) $intent->id, 'status' => $vpos, 'orderAmount' => '100.00', 'currency' => 'EUR'];
            $fields['digest'] = $this->sign($fields, self::SECRET);

            $outcome = $this->gateway()->handleWebhook($this->returnRequest($fields), $conn);
            $this->assertTrue($outcome->verified);
            $this->assertFalse($outcome->isSettled(), "$vpos must not settle");
            $this->assertSame($expected, $outcome->status);
        }
    }

    public function test_no_secret_configured_is_unverified(): void
    {
        $conn = $this->connection(secret: '');
        $fields = ['orderid' => '1', 'status' => 'CAPTURED', 'digest' => 'anything'];
        $outcome = $this->gateway()->handleWebhook($this->returnRequest($fields), $conn);
        $this->assertFalse($outcome->verified);
    }
}
