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

    public function for(Invoice $invoice): InvoiceBalanceData
    {
        $gross = round((float) ($invoice->gross_total ?? 0), 2);
        $credited = round($this->creditedTotal($invoice), 2);
        $rawPaid = round($this->paidTotal($invoice), 2);
        $owed = round($gross - $credited, 2);

        // Fully credited (return) — wins over payment state.
        if ($credited >= $gross - self::EPS && $gross > self::EPS) {
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
        if ($this->isCashTerm($invoice) && ! $this->hasRecordedPayments($invoice)) {
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
    private function hasRecordedPayments(Invoice $invoice): bool
    {
        if (! $invoice->exists) {
            return false;
        }

        return DB::table('payments')
            ->where('invoice_id', $invoice->getKey())
            ->whereNull('deleted_at')
            ->exists();
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
     * concurrent payment writes serialise their cache updates. Caller
     * must already be inside a DB transaction.
     */
    public function recompute(Invoice $invoice): void
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

        $data = $this->for($locked);

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
        $q = DB::table('invoices')
            ->where('credited_invoice_id', $invoice->getKey())
            ->whereNull('deleted_at');

        return (float) InvoiceScope::live($q)->sum('gross_total');
    }

    private function paidTotal(Invoice $invoice): float
    {
        if (! $invoice->exists) {
            return 0.0;
        }

        // Refunds (kind = 'refund') count NEGATIVE — money returned to the
        // customer reduces what's been paid on this invoice (Payment::NET_AMOUNT_SQL).
        $row = DB::table('payments')
            ->where('invoice_id', $invoice->getKey())
            ->whereNull('deleted_at')
            ->selectRaw('COALESCE(SUM('.Payment::NET_AMOUNT_SQL.'), 0) AS net_paid')
            ->first();

        return (float) ($row->net_paid ?? 0);
    }
}
