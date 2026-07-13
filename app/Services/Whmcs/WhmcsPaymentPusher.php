<?php

namespace App\Services\Whmcs;

use App\Models\Invoice;
use App\Models\Payment;
use App\Models\PendingWhmcsInvoice;
use App\Services\InvoiceBalance;
use Throwable;

/**
 * Outbound payment push (ekdosi → WHMCS «σήμανση πληρωμένου»). When an ekdosi
 * invoice issued επί πιστώσει is settled locally, tell the customer's WHMCS to
 * mark its invoice Paid. This is the ONLY place ekdosi writes money to an
 * external system, so it carries four brakes:
 *
 *  1. **opt-in** — never runs unless `companies.whmcs_push_payments` is on.
 *  2. **idempotent** — a `whmcs_payment_pushed_at` marker on the filed row, set
 *     under a row lock, plus a query-first (skip if WHMCS already shows Paid) and
 *     a deterministic `ekdosi-paid:{id}` transid, so a settlement is pushed once.
 *  3. **anti-echo** — requires a REAL ekdosi-origin payment (kind=payment,
 *     transaction_id not `whmcs-paid:*`). A settlement that came FROM the inbound
 *     sync is never pushed back (WHMCS was already Paid anyway) — no ping-pong.
 *  4. **live-only** — never on a cancelled (local or AADE) invoice or a credit
 *     note; only credit-term («επί πιστώσει») receivables.
 *
 * Testable in isolation: the WHMCS status fetch + the mark-paid write are
 * injected callables (native or bridge), so the money logic is exercised
 * without HTTP.
 */
class WhmcsPaymentPusher
{
    public function __construct(private readonly InvoiceBalance $balance) {}

    /**
     * @param  callable(int): (array<string, mixed>|null)  $fetch  WHMCS payload resolver (status/balance)
     * @param  callable(int, float, string): void  $push  Marks the WHMCS invoice paid:
     *                                                    (whmcsInvoiceId, amount, transId) → void; throws on a WHMCS-side failure.
     */
    public function push(Invoice $invoice, callable $fetch, callable $push): PaymentPushResult
    {
        $tenant = $invoice->company;
        if ($tenant === null || ! (bool) $tenant->whmcs_push_payments) {
            return PaymentPushResult::Skipped;   // outbound not opted in
        }

        $row = $this->eligibleRow($invoice);
        if ($row === null) {
            return PaymentPushResult::Skipped;
        }

        // Query WHMCS OUTSIDE any lock. If it already shows Paid, there's nothing
        // to push — just claim the marker so we stop re-checking it.
        $payload = $fetch((int) $row->whmcs_invoice_id);
        if (! is_array($payload)) {
            return PaymentPushResult::Skipped;   // unreachable → never guess; retry later
        }

        $amount = $this->pushAmount($invoice, $payload);
        if (strcasecmp((string) ($payload['status'] ?? ''), 'Paid') === 0 || $amount <= 0.005) {
            $this->claimMarker($row);   // best-effort; nothing sent

            return PaymentPushResult::AlreadyPaid;
        }

        // CLAIM the idempotency marker atomically BEFORE the write — so two
        // concurrent pushes (a manual click racing the auto-push job) can't both
        // reach WHMCS. Exactly one claim wins; the loser skips. We never hold the
        // row lock across the HTTP call: claim, release, then push.
        if (! $this->claimMarker($row)) {
            return PaymentPushResult::Skipped;   // another run already owns this push
        }

        try {
            $push((int) $row->whmcs_invoice_id, $amount, self::transId($invoice));
        } catch (Throwable $e) {
            report($e);
            $this->releaseMarker($row);   // failed → un-claim so a later run retries

            return PaymentPushResult::Failed;
        }

        return PaymentPushResult::Pushed;
    }

    /**
     * The FILED, WHMCS-linked, not-yet-pushed row for a live, credit-term,
     * locally-SETTLED invoice that carries a REAL (non-inbound) ekdosi payment.
     * Returns null when any brake trips.
     */
    private function eligibleRow(Invoice $invoice): ?PendingWhmcsInvoice
    {
        if (! $this->isLiveCreditTermSettled($invoice) || ! $this->hasRealPayment($invoice)) {
            return null;
        }

        return PendingWhmcsInvoice::query()
            ->where('company_id', $invoice->company_id)
            ->where('invoice_id', $invoice->id)
            ->where('status', PendingWhmcsInvoice::STATUS_FILED)
            ->whereNotNull('whmcs_invoice_id')
            ->whereNull('whmcs_payment_pushed_at')
            ->first();
    }

    private function isLiveCreditTermSettled(Invoice $invoice): bool
    {
        return $invoice->local_status !== 'cancelled'
            && $invoice->mydata_state !== 'CANCELLED'
            && ! $invoice->isCreditNote()
            && (int) ($invoice->paymentMethod?->due_days ?? 0) > 0
            && $this->balance->for($invoice)->balance <= 0.005;
    }

    /**
     * At least one REAL ekdosi-origin payment (kind=payment, NOT a
     * `whmcs-paid:*` inbound-sync row). This is the anti-echo brake: a
     * receivable that was closed BY the inbound sync must not be pushed back.
     */
    private function hasRealPayment(Invoice $invoice): bool
    {
        return Payment::query()
            ->where('invoice_id', $invoice->id)
            ->where('kind', 'payment')
            ->where(function ($q) {
                $q->whereNull('transaction_id')
                    ->orWhere('transaction_id', 'not like', 'whmcs-paid:%');
            })
            ->exists();
    }

    /** @param array<string, mixed> $payload */
    private function pushAmount(Invoice $invoice, array $payload): float
    {
        $whmcsBalance = isset($payload['balance']) ? (float) $payload['balance'] : 0.0;
        if ($whmcsBalance > 0.005) {
            return round($whmcsBalance, 2);
        }

        // Fall back to the ekdosi receivable (gross − credited).
        return round((float) $invoice->payableTotal() - (float) $invoice->credited_total, 2);
    }

    /**
     * Atomically claim the push: set the marker only if it is still null. The
     * single conditional UPDATE is the serialisation point — the DB guarantees
     * exactly one caller flips null→now(), so exactly one push proceeds. Returns
     * true iff THIS caller won the claim.
     */
    private function claimMarker(PendingWhmcsInvoice $row): bool
    {
        return PendingWhmcsInvoice::query()
            ->whereKey($row->id)
            ->whereNull('whmcs_payment_pushed_at')
            ->update(['whmcs_payment_pushed_at' => now()]) === 1;
    }

    /** Undo a claim (a push that then failed) so a later run/click retries. */
    private function releaseMarker(PendingWhmcsInvoice $row): void
    {
        PendingWhmcsInvoice::query()
            ->whereKey($row->id)
            ->update(['whmcs_payment_pushed_at' => null]);
    }

    private static function transId(Invoice $invoice): string
    {
        return 'ekdosi-paid:'.$invoice->id;
    }
}
