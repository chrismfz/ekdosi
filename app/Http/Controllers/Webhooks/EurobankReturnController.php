<?php

namespace App\Http\Controllers\Webhooks;

use App\Contracts\WebhookGateway;
use App\Models\PaymentGatewayConnection;
use App\Models\PaymentIntent;
use App\Models\Scopes\CompanyScope;
use App\Services\Payments\PaymentGatewayRegistry;
use App\Services\Payments\PaymentIntentService;
use App\Support\Payments\PaymentOutcome;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Eurobank / Cardlink vPOS return (B1). The acquirer's hosted page redirect-POSTs
 * the transaction result to this endpoint (the customer's browser carries it), so
 * it lives in the CSRF-exempt `webhooks` (api) group — the vPOS DIGEST is the
 * authentication, not a session or CSRF token.
 *
 *   POST /webhooks/payments/eurobank/return?result=success|failure
 *
 * The flow, fail-closed at every step:
 *   1. peek `orderid` (= the PaymentIntent id) to locate the intent + its
 *      connection (untrusted routing only — a forged orderid still fails step 3);
 *   2. the gateway verifies the digest over the raw body against THAT connection's
 *      shared secret (T1) → a normalised PaymentOutcome;
 *   3. cross-check amount / currency / company against the stored intent (T3/T6);
 *   4. on a verified CAPTURED outcome, the generic idempotent settle() writes the
 *      Payment exactly once (T2). The `?result` query is advisory — we trust only
 *      the SIGNED `status` field.
 *
 * It never returns money truth to the browser: it redirects to the portal status
 * page, which reads the intent state. Money is written only by settle().
 */
class EurobankReturnController
{
    public function __invoke(
        Request $request,
        PaymentGatewayRegistry $registry,
        PaymentIntentService $intents,
    ): RedirectResponse {
        // Peek the orderid from the raw body — a plain lookup key, trusted for
        // nothing but finding which intent/secret to verify against.
        $fields = [];
        parse_str($request->getContent(), $fields);
        $orderId = (int) ($fields['orderid'] ?? 0);

        $intent = $orderId > 0
            ? PaymentIntent::query()->withoutGlobalScope(CompanyScope::class)->whereKey($orderId)->first()
            : null;

        if ($intent === null) {
            $this->reject($request, 'intent_not_found', ['orderid' => $orderId]);

            return redirect()->route('portal.home');
        }

        $connection = $this->connectionFor($intent);
        $gateway = $registry->for($intent->gateway);

        if ($connection === null || ! $gateway instanceof WebhookGateway) {
            $this->reject($request, 'connection_or_gateway_missing', ['intent' => $intent->id]);

            return $this->back($intent);
        }

        $outcome = $gateway->handleWebhook($request, $connection);

        if (! $outcome->verified) {
            // T1: forged / mis-signed return — never a side effect.
            $this->reject($request, 'digest_verification_failed', ['intent' => $intent->id]);

            return $this->back($intent);
        }

        if (! $this->matchesIntent($outcome, $intent, $connection, $request)) {
            return $this->back($intent);
        }

        if ($outcome->isSettled()) {
            // Idempotent (T2): a replayed return is a no-op. Amount already verified
            // == the intent's, so settle() uses the intent amount (server-authoritative).
            // The acquirer's txn id is recorded on the Payment for the money trail.
            $intents->settle(
                $intent,
                settledBy: 'webhook:eurobank',
                transactionId: $outcome->providerTxnId,
            );
        } else {
            Log::info('eurobank.return.not_captured', [
                'intent' => $intent->id,
                'status' => $outcome->status,
                'txn' => $outcome->providerTxnId,
            ]);
        }

        return $this->back($intent);
    }

    /**
     * T3 (amount/currency tampering) + T6 (cross-tenant): the signed outcome must
     * name THIS intent, its amount, its currency, and the connection's company.
     */
    private function matchesIntent(
        PaymentOutcome $outcome,
        PaymentIntent $intent,
        PaymentGatewayConnection $connection,
        Request $request,
    ): bool {
        // Strict canonical-orderid check: the signed orderid must be EXACTLY the
        // intent id we looked up (no leading zeros / trailing bytes that (int)-cast
        // to the same key). Not merely tautological — it rejects a malformed orderid
        // that collides on cast.
        if ((string) $outcome->reference !== (string) $intent->id) {
            $this->reject($request, 'reference_mismatch', ['intent' => $intent->id, 'ref' => $outcome->reference]);

            return false;
        }
        // Belt-and-suspenders: connectionFor() already scopes the connection to the
        // intent's company (that's where T6 is actually enforced); this asserts the
        // invariant so a future change to that loader can't silently open a leak.
        if ((int) $connection->company_id !== (int) $intent->company_id) {
            $this->reject($request, 'company_mismatch', ['intent' => $intent->id]);

            return false;
        }
        if ($outcome->amount === null || abs($outcome->amount - (float) $intent->amount) > 0.005) {
            $this->reject($request, 'amount_mismatch', [
                'intent' => $intent->id, 'intent_amount' => (float) $intent->amount, 'outcome_amount' => $outcome->amount,
            ]);

            return false;
        }
        if ($outcome->currency !== null
            && $this->normaliseCurrency($outcome->currency) !== $this->normaliseCurrency((string) $intent->currency)) {
            $this->reject($request, 'currency_mismatch', ['intent' => $intent->id, 'currency' => $outcome->currency]);

            return false;
        }

        return true;
    }

    /**
     * Currency codes for comparison: acquirers echo EUR as either the alpha code
     * ('EUR') or the ISO-4217 numeric ('978'). Fold them so a numeric return can't
     * spuriously fail the check and strand a genuine settlement (the digest already
     * covers the field — this is defence-in-depth, not the primary guard).
     */
    private function normaliseCurrency(string $c): string
    {
        $c = strtoupper(trim($c));

        return $c === '978' ? 'EUR' : $c;
    }

    /**
     * The intent's own connection (same company). withTrashed + is_active is NOT
     * required here (unlike the outbound portal path): if the operator disabled or
     * soft-deleted the method WHILE a payment was in flight, we must still resolve
     * its shared secret to verify the return and settle the money that actually
     * arrived. nullOnDelete never fires on a soft-delete, so the FK still points at
     * the trashed row — include it.
     */
    private function connectionFor(PaymentIntent $intent): ?PaymentGatewayConnection
    {
        if ($intent->payment_gateway_connection_id === null) {
            return null;
        }

        return PaymentGatewayConnection::query()
            ->withoutGlobalScope(CompanyScope::class)
            ->withTrashed()
            ->where('company_id', $intent->company_id)
            ->whereKey($intent->payment_gateway_connection_id)
            ->first();
    }

    /** Send the customer to the portal status page (which reads the intent state). */
    private function back(PaymentIntent $intent): RedirectResponse
    {
        return redirect()->route('portal.payment.show', $intent->id);
    }

    /** Structured warning for a rejected return — never logs the digest or secret. */
    private function reject(Request $request, string $reason, array $context = []): void
    {
        Log::warning('eurobank.return.rejected', array_merge([
            'reason' => $reason,
            'ip' => $request->ip(),
            'result' => (string) $request->query('result', ''),
        ], $context));
    }
}
