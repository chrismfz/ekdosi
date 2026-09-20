<?php

namespace App\Http\Controllers\Webhooks;

use App\Contracts\WebhookGateway;
use App\Models\Customer;
use App\Models\PaymentGatewayConnection;
use App\Models\PaymentGatewayEvent;
use App\Models\PaymentIntent;
use App\Models\Scopes\CompanyScope;
use App\Services\Payments\Gateways\EurobankGateway;
use App\Services\Payments\PaymentGatewayRegistry;
use App\Services\Payments\PaymentIntentService;
use App\Support\Money;
use App\Support\Payments\PaymentOutcome;
use Filament\Notifications\Notification;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
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
            // The gateway logs the acquirer's field names on every return it sees —
            // but it is never reached from here. Log them too, so a validation run
            // whose orderid doesn't resolve still yields the one thing it was for.
            // Keys only, never values.
            $diagnostics = [
                'code' => 'intent_not_found',
                // Same noise keys the gateway strips, so the two field lists are
                // directly comparable wherever they are shown together.
                'posted_order' => EurobankGateway::clampFieldNames(
                    array_keys(array_diff_key($fields, array_flip(EurobankGateway::DIGEST_EXCLUDED))),
                ),
            ];
            Log::info('eurobank.return.fields', $diagnostics);
            $this->reject($request, 'intent_not_found', ['orderid' => $orderId],
                orderId: (string) $orderId, diagnostics: $diagnostics);

            return redirect()->route('portal.home');
        }

        $connection = $this->connectionFor($intent);
        $gateway = $registry->for($intent->gateway);

        if ($connection === null || ! $gateway instanceof WebhookGateway) {
            $this->reject($request, 'connection_or_gateway_missing', ['intent' => $intent->id], intent: $intent);

            return $this->back($intent);
        }

        $outcome = $gateway->handleWebhook($request, $connection);

        if (! $outcome->verified && ! $this->claimsCapture($request)) {
            // The `cancelUrl` leg posts a different field set, so an ordinary «Άκυρο»
            // can fail the strict field check. It settles nothing either way, so file
            // it as the routine non-capture it is rather than as a forgery attempt —
            // otherwise real cancellations become indistinguishable from attacks in
            // «Log πύλης». The money path is untouched: a body claiming CAPTURED
            // still goes through the branch below.
            // Its own reason code, NOT the acquirer's «not_captured»: the digest did
            // not verify, so this is either a cancel leg with fields we don't list or
            // someone probing with forged signatures. Filing it as the ordinary
            // business outcome would hide the probes; filing it as a rejection would
            // make every «Άκυρο» look like an attack. It is neither, so name it.
            $this->record($request, $intent, PaymentGatewayEvent::OUTCOME_IGNORED, 'unverified_non_capture', $outcome);

            return $this->back($intent);
        }

        if (! $outcome->verified) {
            // T1: forged / mis-signed return — never a side effect.
            $this->reject($request, 'digest_verification_failed', ['intent' => $intent->id], intent: $intent, outcome: $outcome);

            // Reaching here means the body DID claim a capture — the branch above
            // already returned for an unverified non-capture (the `cancelUrl` leg,
            // which posts a different field set and must not raise an alarm). So a
            // failure here is either an attack or the acquirer changing a field we
            // don't know (see EurobankGateway::RETURN_FIELD_ORDER), and in the second
            // case EVERY capture is now bouncing. Company-wide cooldown so a sprayer
            // can't turn it into a flood.
            $this->alertOperators($intent, 'digest_verification_failed',
                'Απορρίφθηκε επιτυχημένη χρέωση: η υπογραφή (digest) δεν επαληθεύτηκε. '
                .'Αν επαναλαμβάνεται, ΚΑΜΙΑ πληρωμή δεν καταχωρείται — έλεγξε το «Log πύλης» '
                .'και τα πεδία που στέλνει η τράπεζα.', perCompany: true);

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
            // settle() is authoritative about what happened (it decides under the
            // row lock), so the audit row is written from ITS answer rather than a
            // status we read before the call.
            $result = $intents->settle(
                $intent,
                settledBy: 'webhook:eurobank',
                transactionId: $outcome->providerTxnId,
            );

            if ($result === PaymentIntentService::SETTLE_OK) {
                // A capture reporting no transaction id leaves the per-transaction
                // dedup inert. We settle it anyway — the bank took the customer's
                // money, and refusing would strand a real payment (the alternative
                // rails this gateway advertises may not report one); the canonical
                // digest rebuild + mid/status/currency pins, not the dedup, are what
                // stop a re-partitioned replay. But it IS recorded as the reason on
                // the settled event, so «Log πύλης» can be filtered for it, and the
                // operators are told. Only here, inside SETTLE_OK: announcing it
                // before settle() decided would claim money was recorded when a
                // replay/cancelled intent wrote nothing.
                $unidentified = ! filled($outcome->providerTxnId);
                $this->record($request, $intent, PaymentGatewayEvent::OUTCOME_SETTLED,
                    $unidentified ? 'settled_without_transaction_id' : null, $outcome);
                if ($unidentified) {
                    $this->alertOperators($intent, 'settled_without_transaction_id',
                        'Καταχωρίστηκε είσπραξη χωρίς κωδικό συναλλαγής από την τράπεζα — '
                        .'δεν μπορεί να ελεγχθεί για διπλοκαταχώριση. Δες «Log πύλης».');
                }
                // The webhook settles UNATTENDED — ring the operators' bell so they
                // know money landed (an operator-driven settle is already visible to
                // the operator doing it, so only this automatic path notifies).
                $this->notifyOperators($intent, $outcome);
            } elseif ($result === PaymentIntentService::SETTLE_DUPLICATE_TRANSACTION) {
                // This acquirer transaction already paid another intent — a replayed
                // (possibly re-partitioned) return. No money, loud audit row.
                $this->reject($request, 'duplicate_transaction', [
                    'intent' => $intent->id, 'txn' => $outcome->providerTxnId,
                ], intent: $intent, outcome: $outcome);
            } elseif ($result === PaymentIntentService::SETTLE_CANCELLED) {
                // The operator cancelled while the customer was still on the hosted
                // page, and the charge went through anyway: money taken, none
                // recorded. The one verified-CAPTURED branch that used to be filed
                // as a routine «already settled».
                $this->reject($request, 'settle_on_cancelled_intent', [
                    'intent' => $intent->id, 'txn' => $outcome->providerTxnId,
                ], intent: $intent, outcome: $outcome);
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
     * name THIS terminal (`mid`), THIS intent, its amount, its currency, and the
     * connection's company.
     */
    private function matchesIntent(
        PaymentOutcome $outcome,
        PaymentIntent $intent,
        PaymentGatewayConnection $connection,
        Request $request,
    ): bool {
        // The vPOS digest is a concatenation of the returned field VALUES with no
        // delimiters, in the order they arrived — so the field BOUNDARIES are not
        // signed, only the resulting byte string is. Pin the one boundary that
        // matters: `mid` must be exactly this connection's configured terminal.
        //
        // Without this, a customer who completed one genuine payment could replay
        // its correctly-signed return with the same bytes re-split across
        // mid|orderid (`mid=MID12`,`orderid=3…` instead of `mid=MID123`,`orderid=…`),
        // keeping the digest valid while aiming the settlement at a DIFFERENT
        // intent — one payment settling two intents. The per-transaction dedup in
        // PaymentIntentService::settle() is the second, independent guard.
        //
        // A return with no `mid` at all is refused for the same reason: its absence
        // is itself the re-partition (those bytes went somewhere else). Every real
        // Cardlink return carries it, and a rejection is logged as `mid_mismatch`
        // in «Log πύλης» so a protocol surprise is visible immediately.
        // Trim ONLY the configured side (operator-typed in a plain Filament
        // TextInput, so a stray space there must not refuse every capture). The
        // REPORTED value is compared raw: it is attacker-chosen, and trimming it
        // would let whitespace move across the very boundary this pin exists to fix.
        $configuredMid = trim((string) (($connection->config ?? [])['merchant_id'] ?? ''));
        $reportedMid = (string) $outcome->merchantId;
        if ($configuredMid === '' || ! hash_equals($configuredMid, $reportedMid)) {
            $this->reject($request, 'mid_mismatch', [
                'intent' => $intent->id, 'mid' => $reportedMid,
            ], intent: $intent, outcome: $outcome);

            return false;
        }
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
        // Required, not optional: a return may omit `currency` only by folding its
        // bytes into a neighbouring field, which is the re-partition move itself.
        if ($outcome->currency === null
            || $this->normaliseCurrency($outcome->currency) !== $this->normaliseCurrency((string) $intent->currency)) {
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

    /** Fit an untrusted value to its column, keeping the row writable. */
    private static function clamp(?string $value, int $max): ?string
    {
        return $value === null ? null : mb_substr($value, 0, $max);
    }

    /**
     * Does this body CLAIM a successful capture? Reads the raw posted `status`, so
     * it is untrusted by construction — it may only gate alerting, never money.
     */
    private function claimsCapture(Request $request): bool
    {
        $fields = [];
        parse_str($request->getContent(), $fields);

        return strtoupper(trim((string) ($fields['status'] ?? ''))) === 'CAPTURED';
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
        ?array $diagnostics = null,
    ): void {
        Log::warning('eurobank.return.rejected', array_merge([
            'reason' => $reason,
            'ip' => $request->ip(),
            'result' => (string) $request->query('result', ''),
        ], $context));

        $this->record($request, $intent, PaymentGatewayEvent::OUTCOME_REJECTED, $reason, $outcome, $orderId, $diagnostics);

        // A refusal AFTER a verified CAPTURED status means the bank took the money and
        // we declined to record it — exactly the event nobody should have to discover
        // by reading «Log πύλης».
        if ($outcome?->isSettled() === true && $intent !== null) {
            $amount = Money::eur((float) ($outcome->amount ?? $intent->amount));
            // mid/currency mismatches mean the TERMINAL is misconfigured (or the
            // acquirer changed), so every in-flight capture hits them on a different
            // intent. Cool those down per COMPANY — one «your terminal is wrong»
            // rather than hundreds. Per-intent stays right for the rest.
            $systemic = in_array($reason, ['mid_mismatch', 'currency_mismatch'], true);
            $this->alertOperators($intent, $reason,
                "Η τράπεζα χρέωσε {$amount} αλλά η πληρωμή ΔΕΝ καταχωρίστηκε (αιτία: {$reason}). "
                .'Δες «Log πύλης» — αν επαναλαμβάνεται, έλεγξε τις ρυθμίσεις του τερματικού.',
                perCompany: $systemic);
        }
    }

    /**
     * Ring the tenant's operators about a money-path anomaly, at most once an hour
     * per key so a replayer (or a sprayer) can't turn the signal into a flood.
     * `perCompany` widens the cooldown from one intent to the whole tenant — right
     * for «the acquirer changed something», where every intent is affected and one
     * alert says it all.
     *
     * FULLY best-effort, cache included: this runs on the rejection path, and a
     * locked cache table must never turn a routine guard rejection into a 500
     * instead of the customer's redirect.
     */
    private function alertOperators(?PaymentIntent $intent, string $reason, string $body, bool $perCompany = false): void
    {
        if ($intent === null) {
            return;
        }

        try {
            $recipients = $intent->company?->users;
            if ($recipients === null || $recipients->isEmpty()) {
                return;
            }

            $key = $perCompany
                ? 'eurobank:alert:company:'.$intent->company_id.':'.$reason
                : 'eurobank:alert:intent:'.$intent->id.':'.$reason;

            // Claim the hour AFTER we know there is someone to tell, and release it
            // if the send fails — otherwise a single hiccup silences the next hour
            // of a genuine «every capture is bouncing» outage.
            if (! Cache::add($key, true, now()->addHour())) {
                return;
            }

            try {
                Notification::make()
                    ->title('Πρόβλημα σε είσπραξη μέσω πύλης')
                    ->body($body)
                    ->icon('heroicon-o-exclamation-triangle')
                    ->danger()
                    ->sendToDatabase($recipients);
            } catch (Throwable $e) {
                Cache::forget($key);
                throw $e;
            }
        } catch (Throwable $e) {
            Log::warning('eurobank.return.alert_failed', ['intent' => $intent->id, 'error' => $e->getMessage()]);
        }
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
        ?array $diagnostics = null,
    ): void {
        try {
            // Log the RAW acquirer status («CAPTURED»/«REFUSED»… — what the bank's
            // notification shows), not our normalised one; fall back to the normalised
            // outcome when the return carried none.
            $body = [];
            parse_str($request->getContent(), $body);
            $rawStatus = filled($body['status'] ?? null) ? (string) $body['status'] : $providerOutcome?->status;

            // Every value below is chosen by whoever POSTed, and the columns are
            // narrow (varchar 40/8/64, decimal(14,2)). On MariaDB in strict mode an
            // over-long value makes create() throw, the catch swallows it, and NO
            // «Log πύλης» row is written — letting anyone who can reach this
            // unauthenticated endpoint erase their own audit trail. Clamp to the
            // column widths so the row is always written, truncated at worst.
            // (SQLite ignores varchar lengths, so only clamping in PHP is testable.)
            $orderIdValue = $orderId ?? ($intent?->id !== null ? (string) $intent->id : null);
            $amount = $providerOutcome?->amount;
            $amount = $amount !== null && abs($amount) < 1.0e10 ? $amount : null;

            PaymentGatewayEvent::create([
                'company_id' => $intent?->company_id,
                'payment_intent_id' => $intent?->id,
                'gateway' => 'eurobank',
                'order_id' => self::clamp($orderIdValue, 64),
                'outcome' => $outcome,
                'reason' => $reason,
                'verified' => (bool) ($providerOutcome?->verified ?? false),
                'provider_status' => self::clamp($rawStatus, 40),
                'transaction_id' => self::clamp($providerOutcome?->providerTxnId, 64),
                'amount' => $amount,
                'currency' => self::clamp($providerOutcome?->currency, 8),
                'ip' => $request->ip(),
                // Clamped for the same reason as the columns above: `message` comes
                // verbatim from the posted body and, though the column is TEXT, a
                // large enough value still makes create() throw — and the catch
                // below would swallow it, leaving NO audit row at all.
                'message' => self::clamp($providerOutcome?->message, 2000),
                // Why the gateway ruled the way it did, so «Log πύλης» explains a
                // refusal on its own instead of sending an operator to a server log.
                'diagnostics' => $diagnostics ?? ($providerOutcome?->diagnostics ?: null),
            ]);
        } catch (Throwable $e) {
            Log::warning('eurobank.return.event_log_failed', ['error' => $e->getMessage()]);
        }
    }
}
