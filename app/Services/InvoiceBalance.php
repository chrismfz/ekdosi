<?php

namespace App\Services;

use App\Enums\PaymentStatus;
use App\Models\Invoice;
use App\Models\Payment;
use App\Support\InvoiceScope;
use Illuminate\Support\Facades\DB;

/**
 * THE single source of truth for an invoice's money status. Nothing
 * else computes owed/paid/credited/balance/status independently.
 *
 *   owed    = gross_total − credited_total
 *   balance = owed − paid_total
 *
 * - credited_total = Σ gross of issued, non-cancelled credit notes
 *   (credited_invoice_id = this). A CANCELLED credit note doesn't count.
 * - paid_total = Σ amount of non-trashed payments allocated to this invoice.
 * - Cash-term invoices (payment_method.due_days = 0, or no payment
 *   method) are settled at issue → status `paid` AND balance 0 / paid =
 *   owed (mirrors legacy GET_CUSTOMER_BALANCE, which excludes cash-term
 *   invoices from the receivable). The figures must AGREE with the badge:
 *   no "€X outstanding" next to "paid".
 *
 * Money compared with a 0.005 tolerance so 2dp rounding never flips a
 * status spuriously (same style as MyDataSubmitter's VAT matching).
 *
 * `for()` computes live from durable rows (for UI). `recompute()`
 * persists the denormalised cache columns and MUST run inside the
 * caller's transaction (like RecomputeInvoiceTotals).
 */
class InvoiceBalance
{
    private const EPS = 0.005;

    /**
     * @param  bool  $locking  Read the PAYMENT sum as a LOCKING read (FOR UPDATE)
     *                         instead of a plain consistent read. Set by
     *                         recompute() so the payment aggregate bypasses the
     *                         transaction's MVCC read view (which, under
     *                         REPEATABLE READ, may have been established BEFORE
     *                         the invoice row was locked — by an outer
     *                         transaction's earlier read — and would otherwise
     *                         serve a stale sum that misses a concurrently
     *                         committed payment: the MON-3 stale-`paid_total`
     *                         bug). UI callers leave it false: a read-only
     *                         display must never take row locks.
     *
     *                         NOTE the credited-notes sum is DELIBERATELY not
     *                         locked (see creditedTotal): locking OTHER invoice
     *                         rows here would close a deadlock cycle with the
     *                         credit-note filing path (which locks the note row
     *                         then, via InvoiceObserver, the original) against
     *                         the payment path (original then note). credited_
     *                         total self-heals — every credit-note change re-
     *                         fires recompute(original) — so a momentarily stale
     *                         read corrects on the next event without the lock.
     */
    public function for(Invoice $invoice, bool $locking = false): InvoiceBalanceData
    {
        // `gross` stays the document value (net+VAT) for display; `payable` is the
        // real COLLECTIBLE (net+VAT + fees − withholding, per AADE [208]) and is the
        // basis for owed/balance — what the customer actually pays / we receive.
        $gross = round((float) ($invoice->gross_total ?? 0), 2);
        $payable = round($invoice->payableTotal(), 2);
        $credited = round($this->creditedTotal($invoice), 2);
        $rawPaid = round($this->paidTotal($invoice, $locking), 2);
        $owed = round($payable - $credited, 2);

        // Fully credited (return) — wins over payment state.
        if ($credited >= $payable - self::EPS && $payable > self::EPS) {
            return new InvoiceBalanceData(
                gross: $gross, credited: $credited, paid: $rawPaid,
                owed: $owed, balance: round($owed - $rawPaid, 2),
                status: PaymentStatus::Credited,
            );
        }

        // Cash-term (due_days = 0, or no payment method): settled the
        // moment it's issued — legacy GET_CUSTOMER_BALANCE never counts
        // it as a receivable. So there is NOTHING outstanding: report it
        // as paid-in-full with a zero balance (the figures must AGREE
        // with the Paid badge — a €X "balance" next to "Εξοφλημένο" is
        // the contradiction this branch's review surfaced).
        //
        // EXCEPTION (money-trail): once the operator records an EXPLICIT
        // payment row on a cash-term invoice (e.g. to log the actual
        // Stripe/POS receipt for the books), we stop synthesising and track
        // it from the real rows — exactly like a credit-term invoice. It
        // then nets to zero (gross owed − recorded paid) and the receivables
        // predicate (DashboardMetrics / Customer / ledger) includes it
        // symmetrically, so no phantom credit appears. With NO recorded
        // payments it stays the synthetic settled-at-issue default, so the
        // ~6.7k imported invoices (whose legacy payments are on-account) are
        // unchanged.
        // …and it does not apply to a DRAFT. A draft — in particular an offered
        // «προτιμολόγιο» — has no issue to be settled at, so synthesising payment
        // for it would both misreport it as paid (the thing that made a
        // paid-looking, unpaid proforma so confusing) and make it impossible to
        // point credit at it: applyCredit() caps on this balance and would see zero.
        //
        // Deliberately `!== 'draft'` and not `=== 'active'`: a CANCELLED cash-term
        // invoice keeps its previous synthetic settlement, so voiding a document
        // cannot conjure a phantom receivable out of it.
        if ($invoice->local_status !== 'draft'
            && $this->isCashTerm($invoice)
            && ! $this->hasRecordedPayments($invoice, $locking)) {
            return new InvoiceBalanceData(
                gross: $gross, credited: $credited, paid: $owed,
                owed: $owed, balance: 0.0,
                status: PaymentStatus::Paid,
            );
        }

        // Credit-term (or cash-term WITH recorded payments): a real
        // receivable tracked against recorded payments.
        $balance = round($owed - $rawPaid, 2);
        $status = match (true) {
            $rawPaid > $owed + self::EPS => PaymentStatus::Overpaid,
            abs($balance) <= self::EPS => PaymentStatus::Paid,
            $rawPaid > self::EPS => PaymentStatus::Partial,
            default => PaymentStatus::Unpaid,
        };

        return new InvoiceBalanceData(
            gross: $gross, credited: $credited, paid: $rawPaid,
            owed: $owed, balance: $balance, status: $status,
        );
    }

    /**
     * Has the operator recorded any explicit payment row (any kind) against
     * this invoice? Distinguishes a cash-term invoice that's merely
     * settled-at-issue (no rows) from one where the real receipt was logged
     * for the money trail. Only queried for cash-term invoices (credit-term
     * never reaches here), so it adds no cost to the common path.
     */
    private function hasRecordedPayments(Invoice $invoice, bool $locking = false): bool
    {
        if (! $invoice->exists) {
            return false;
        }

        $q = DB::table('payments')
            ->where('invoice_id', $invoice->getKey())
            ->whereNull('deleted_at');

        // recompute path: a locking read so a concurrently-committed payment
        // can't stay hidden behind a stale read view and wrongly send a
        // cash-term invoice down the synthetic settled-at-issue branch. A
        // locking COUNT (`… for update`) mirrors the SUM aggregates above; we
        // avoid EXISTS here because it wraps the lock in a scalar subquery
        // (`select exists(select * … for update)`) whose locking semantics are
        // less portable across MariaDB versions. The plain UI path keeps the
        // cheaper EXISTS.
        if ($locking) {
            return $q->lockForUpdate()->count() > 0;
        }

        return $q->exists();
    }

    /** Cash-term = due_days 0 OR no payment method (not a receivable). */
    private function isCashTerm(Invoice $invoice): bool
    {
        $dueDays = $invoice->relationLoaded('paymentMethod')
            ? $invoice->paymentMethod?->due_days
            : optional($invoice->paymentMethod()->first())->due_days;

        return (int) ($dueDays ?? 0) === 0;
    }

    /**
     * Recompute + persist the cache columns. Locks the invoice row so
     * concurrent payment writes serialise their cache updates, then reads the
     * payment sum under that lock (MON-3). The lock + read + write MUST live in
     * one transaction to be effective; if the caller already opened one we join
     * it, otherwise we open our own — so the guarantee holds even for callers
     * that aren't transactional (e.g. RecomputeInvoiceTotals on the Filament
     * edit path, where the panel doesn't wrap saves in a transaction).
     */
    public function recompute(Invoice $invoice): void
    {
        if (DB::transactionLevel() === 0) {
            DB::transaction(fn () => $this->recomputeLocked($invoice));

            return;
        }

        $this->recomputeLocked($invoice);
    }

    private function recomputeLocked(Invoice $invoice): void
    {
        // Lock + read the row we're about to write, and compute FROM the
        // locked instance (not a discarded lock) so the figures match the
        // row under the lock. paymentMethod is preloaded to avoid an N+1
        // in status().
        $locked = $invoice->newQuery()->whereKey($invoice->getKey())->lockForUpdate()->first();
        if ($locked === null) {
            return;   // deleted between caller and lock — nothing to cache
        }
        $locked->loadMissing('paymentMethod');

        // locking: true → the PAYMENT sum runs FOR UPDATE, so it reads the
        // latest COMMITTED payment rows rather than this transaction's
        // (possibly pre-lock) MVCC snapshot. Without this a concurrent payment
        // that committed after the read view was opened could be lost — the
        // stale-cache overwrite MON-3 flagged. (The credited-notes sum stays a
        // plain read on purpose — see creditedTotal for the deadlock reason.)
        $data = $this->for($locked, locking: true);

        $locked->forceFill([
            'paid_total' => $data->paid,
            'credited_total' => $data->credited,
            'payment_status' => $data->status->value,
        ])->save();
    }

    private function creditedTotal(Invoice $invoice): float
    {
        if (! $invoice->exists) {
            return 0.0;
        }

        // Issued, non-cancelled credit notes reduce what's owed — same
        // "live invoice" filter used for receivables (null state = issued
        // but not yet filed still counts; works for off-mode tenants that
        // never reach VALID). A CANCELLED credit note does not.
        //
        // Deliberately a PLAIN read even under recompute(): a FOR UPDATE here
        // locks the credit-note INVOICE rows, which — paired with the invoice-
        // row lock recompute() already holds — would deadlock against the
        // credit-note filing path (locks the note, then the original via the
        // observer) vs the payment path (original, then the note). credited_
        // total self-heals via InvoiceObserver re-firing recompute(original) on
        // every credit-note change, so the lock buys nothing but the cycle.
        $q = DB::table('invoices')
            ->where('credited_invoice_id', $invoice->getKey())
            ->whereNull('deleted_at');

        // Credit notes reduce the owed in the SAME (payable) unit as the original.
        // COALESCE so a credit note not yet recomputed (null payable_total) still
        // contributes its document gross.
        return (float) InvoiceScope::live($q)->sum(DB::raw('COALESCE(payable_total, gross_total)'));
    }

    private function paidTotal(Invoice $invoice, bool $locking = false): float
    {
        if (! $invoice->exists) {
            return 0.0;
        }

        // Refunds (kind = 'refund') count NEGATIVE — money returned to the
        // customer reduces what's been paid on this invoice (Payment::NET_AMOUNT_SQL).
        $q = DB::table('payments')
            ->where('invoice_id', $invoice->getKey())
            ->whereNull('deleted_at');

        // FOR UPDATE (recompute path only) reads the latest committed payment
        // rows, bypassing a stale MVCC read view — see for()'s $locking note.
        // Aggregate + FOR UPDATE is legal in InnoDB; it locks the matched
        // payment rows, which we already serialise behind the invoice-row lock.
        if ($locking) {
            $q->lockForUpdate();
        }

        $row = $q->selectRaw('COALESCE(SUM('.Payment::NET_AMOUNT_SQL.'), 0) AS net_paid')->first();

        return (float) ($row->net_paid ?? 0);
    }
}
