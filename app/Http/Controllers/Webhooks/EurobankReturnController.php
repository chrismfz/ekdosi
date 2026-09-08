<?php

namespace App\Http\Controllers\Webhooks;

use App\Contracts\WebhookGateway;
use App\Models\Customer;
use App\Models\PaymentGatewayConnection;
use App\Models\PaymentGatewayEvent;
use App\Models\PaymentIntent;
use App\Models\Scopes\CompanyScope;
use App\Services\Payments\PaymentGatewayRegistry;
use App\Services\Payments\PaymentIntentService;
use App\Support\Money;
use App\Support\Payments\PaymentOutcome;
use Filament\Notifications\Notification;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Throwable;

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
            $this->reject($request, 'intent_not_found', ['orderid' => $orderId], orderId: (string) $orderId);

            return redirect()->route('portal.home');
        }

        $connection = $this->connectionFor($intent);
        $gateway = $registry->for($intent->gateway);

        if ($connection === null || ! $gateway instanceof WebhookGateway) {
            $this->reject($request, 'connection_or_gateway_missing', ['intent' => $intent->id], intent: $intent);

            return $this->back($intent);
        }

        $outcome = $gateway->handleWebhook($request, $connection);

        if (! $outcome->verified) {
            // T1: forged / mis-signed return — never a side effect.
            $this->reject($request, 'digest_verification_failed', ['intent' => $intent->id], intent: $intent, outcome: $outcome);

            return $this->back($intent);
        }

        if (! $this->matchesIntent($outcome, $intent, $connection, $request)) {
            return $this->back($intent);
        }

        if ($outcome->isSettled()) {
            // Idempotent (T2): a replayed return is a no-op. Amount already verified
            // == the intent's, so settle() uses the intent amount (server-authoritative).
            // The acquirer's txn id is recorded on the Payment for the money trail.
            // A return that arrives when the intent is NOT settleable (already settled
            // = a replay, or human-cancelled) writes no money → log it as IGNORED, not
            // a second «Καταχωρίστηκε» (keeps the audit truthful).
            $settleable = in_array($intent->status, [PaymentIntent::STATUS_PENDING, PaymentIntent::STATUS_EXPIRED], true);
            $intents->settle(
                $intent,
                settledBy: 'webhook:eurobank',
                transactionId: $outcome->providerTxnId,
            );
            if ($settleable) {
                $this->record($request, $intent, PaymentGatewayEvent::OUTCOME_SETTLED, null, $outcome);
                // The webhook settles UNATTENDED — ring the operators' bell so they
                // know money landed (an operator-driven settle is already visible to
                // the operator doing it, so only this automatic path notifies).
                $this->notifyOperators($intent, $outcome);
            } else {
                $this->record($request, $intent, PaymentGatewayEvent::OUTCOME_IGNORED, 'already_settled', $outcome);
            }
        } else {
            Log::info('eurobank.return.not_captured', [
                'intent' => $intent->id,
                'status' => $outcome->status,
                'txn' => $outcome->providerTxnId,
            ]);
            $this->record($request, $intent, PaymentGatewayEvent::OUTCOME_IGNORED, 'not_captured', $outcome);
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
            $this->reject($request, 'reference_mismatch', ['intent' => $intent->id, 'ref' => $outcome->reference], intent: $intent, outcome: $outcome);

            return false;
        }
        // Belt-and-suspenders: connectionFor() already scopes the connection to the
        // intent's company (that's where T6 is actually enforced); this asserts the
        // invariant so a future change to that loader can't silently open a leak.
        if ((int) $connection->company_id !== (int) $intent->company_id) {
            $this->reject($request, 'company_mismatch', ['intent' => $intent->id], intent: $intent, outcome: $outcome);

            return false;
        }
        if ($outcome->amount === null || abs($outcome->amount - (float) $intent->amount) > 0.005) {
            $this->reject($request, 'amount_mismatch', [
                'intent' => $intent->id, 'intent_amount' => (float) $intent->amount, 'outcome_amount' => $outcome->amount,
            ], intent: $intent, outcome: $outcome);

            return false;
        }
        if ($outcome->currency !== null
            && $this->normaliseCurrency($outcome->currency) !== $this->normaliseCurrency((string) $intent->currency)) {
            $this->reject($request, 'currency_mismatch', ['intent' => $intent->id, 'currency' => $outcome->currency], intent: $intent, outcome: $outcome);

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

    /**
     * Structured warning for a rejected return — never logs the digest or secret —
     * AND a durable «Log πύλης» row so the operator sees the rejection (and why)
     * without grepping laravel.log.
     */
    private function reject(
        Request $request,
        string $reason,
        array $context = [],
        ?PaymentIntent $intent = null,
        ?PaymentOutcome $outcome = null,
        ?string $orderId = null,
    ): void {
        Log::warning('eurobank.return.rejected', array_merge([
            'reason' => $reason,
            'ip' => $request->ip(),
            'result' => (string) $request->query('result', ''),
        ], $context));

        $this->record($request, $intent, PaymentGatewayEvent::OUTCOME_REJECTED, $reason, $outcome, $orderId);
    }

    /**
     * Ring the tenant's operators' bell on an unattended gateway settlement, so a
     * payment that arrived while nobody was watching is noticed. Best-effort: a
     * notification hiccup must never break the settlement or the customer redirect.
     */
    private function notifyOperators(PaymentIntent $intent, PaymentOutcome $outcome): void
    {
        try {
            $company = $intent->company;
            $recipients = $company?->users;
            if ($recipients === null || $recipients->isEmpty()) {
                return;
            }

            $customer = Customer::query()
                ->withoutGlobalScope(CompanyScope::class)
                ->where('company_id', $intent->company_id)
                ->whereKey($intent->customer_id)
                ->first();

            $amount = Money::eur((float) $intent->amount);
            $who = $customer?->name ?? 'Πελάτης';
            $gateway = app(PaymentGatewayRegistry::class)->label((string) $intent->gateway);
            $txn = filled($outcome->providerTxnId) ? ' (κωδ. '.$outcome->providerTxnId.')' : '';

            Notification::make()
                ->title('Νέα πληρωμή μέσω πύλης')
                ->body("{$who} πλήρωσε {$amount} μέσω {$gateway}{$txn}.")
                ->icon('heroicon-o-banknotes')
                ->success()
                ->sendToDatabase($recipients);
        } catch (Throwable $e) {
            Log::warning('eurobank.return.notify_failed', ['intent' => $intent->id, 'error' => $e->getMessage()]);
        }
    }

    /**
     * Write one «Log πύλης» audit row. Best-effort: a logging failure must NEVER
     * break settlement or the customer's redirect, so it is fully swallowed.
     */
    private function record(
        Request $request,
        ?PaymentIntent $intent,
        string $outcome,
        ?string $reason,
        ?PaymentOutcome $providerOutcome,
        ?string $orderId = null,
    ): void {
        try {
            // Log the RAW acquirer status («CAPTURED»/«REFUSED»… — what the bank's
            // notification shows), not our normalised one; fall back to the normalised
            // outcome when the return carried none.
            $body = [];
            parse_str($request->getContent(), $body);
            $rawStatus = filled($body['status'] ?? null) ? (string) $body['status'] : $providerOutcome?->status;

            PaymentGatewayEvent::create([
                'company_id' => $intent?->company_id,
                'payment_intent_id' => $intent?->id,
                'gateway' => 'eurobank',
                'order_id' => $orderId ?? ($intent?->id !== null ? (string) $intent->id : null),
                'outcome' => $outcome,
                'reason' => $reason,
                'verified' => (bool) ($providerOutcome?->verified ?? false),
                'provider_status' => $rawStatus,
                'transaction_id' => $providerOutcome?->providerTxnId,
                'amount' => $providerOutcome?->amount,
                'currency' => $providerOutcome?->currency,
                'ip' => $request->ip(),
                'message' => $providerOutcome?->message,
            ]);
        } catch (Throwable $e) {
            Log::warning('eurobank.return.event_log_failed', ['error' => $e->getMessage()]);
        }
    }
}
