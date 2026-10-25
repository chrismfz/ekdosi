<?php

namespace App\Services\WhmcsInbox;

use App\Models\Invoice;
use App\Models\Payment;
use App\Models\PendingWhmcsInvoice;
use App\Services\Whmcs\WhmcsPaidReceipt;
use Illuminate\Support\Facades\DB;

/**
 * Records the money-trail receipt for a WHMCS invoice that was ALREADY PAID in
 * WHMCS by the time we issue it in ekdosi (the «Eurobank vPOS / έμβασμα» case).
 *
 * Why this exists: WHMCS collected the money (gateway + transaction id + date);
 * without this, an issued cash-term invoice is «settled at issue» with NO row
 * saying HOW/WHEN it was paid — so on the Καρτέλα, in a dispute, there's nothing
 * to show. WhmcsPaymentSyncer deliberately does NOT cover this: it only closes
 * OPEN credit-term receivables (only-if-open), so a cash-term invoice paid at
 * source is invisible to it. We fill that gap at the issue point instead.
 *
 * What it is (and isn't):
 *  - A plain, EDITABLE/DELETABLE Payment row on the invoice's «Πληρωμές» tab —
 *    NOT a legal/myDATA document. A wrong one is fixed there, zero AADE impact.
 *  - Best-effort: callers wrap it so a failure NEVER blocks the (already
 *    committed) AADE filing.
 *
 * Safety / correctness:
 *  - Fires only when the WHMCS snapshot says the invoice is fully `Paid`.
 *  - Only for an ISSUED (pending row = FILED) row whose invoice is live and not a
 *    credit note — never a still-drafted document (guards on the pending status,
 *    not local_status, so it works for off-mode tenants too, whose issued
 *    invoices keep local_status='draft').
 *  - Records the COLLECTIBLE owed (InvoiceBalance owed = payable − credited), NOT
 *    the synthetic cash-term balance (0 at issue) and deliberately NOT the WHMCS-
 *    charged € — we follow the WHMCS id trail while keeping OUR invoice netted to
 *    zero. Taking owed straight from InvoiceBalance keeps it from drifting.
 *  - `transaction_id` = the real acquirer/vPOS ref (visible in «Κωδ. συναλλαγής»),
 *    or the deterministic `WhmcsPaidReceipt::transactionKey()` when the payload
 *    has none.
 *  - Idempotent: skips (withTrashed) if a row with that same id already exists on
 *    the invoice — so a re-issue never doubles and a deliberately deleted/refunded
 *    receipt is never resurrected — and also skips when ANY real payment is already
 *    on the invoice. (For a vPOS-ref id the WhmcsPaymentSyncer's whmcs-paid-keyed
 *    dedup won't recognise this row, but the syncer only touches credit-term
 *    invoices, so the cash-term vPOS case is unaffected; the credit-term-paid-at-
 *    issue-then-refunded edge is an accepted tradeoff — see BACKLOG.)
 *  - Serialised under a lockForUpdate on the invoice so a concurrent recorder /
 *    manual payment can't both pass the dedup check.
 */
class WhmcsReceiptRecorder
{
    private const EPS = 0.005;

    /**
     * Record the receipt if the WHMCS snapshot shows Paid. Returns the amount
     * recorded (0.0 when nothing was — not paid, not eligible, or already logged).
     */
    public function recordIfPaid(PendingWhmcsInvoice $pending, Invoice $invoice): float
    {
        // Only an ISSUED WHMCS row (FILED) — never a still-drafted one. Guarding on
        // the pending status (not invoice.local_status) keeps off-mode tenants
        // working, whose issued invoices stay local_status='draft'.
        if ($pending->status !== PendingWhmcsInvoice::STATUS_FILED) {
            return 0.0;
        }

        $payload = $pending->payload;
        if (! WhmcsPaidReceipt::isPaid($payload)) {
            return 0.0;
        }

        if ($invoice->customer_id === null
            || $invoice->local_status === 'cancelled'
            || $invoice->mydata_state === 'CANCELLED'
            || $invoice->isCreditNote()) {
            return 0.0;
        }

        $whmcsInvoiceId = (int) $pending->whmcs_invoice_id;
        [$ref, $gateway] = self::provenance($payload);
        // The visible «Κωδ. συναλλαγής»: the real acquirer/vPOS ref when present,
        // else the deterministic whmcs-paid key (which the WhmcsPaymentSyncer also
        // dedups on). NOTE: with the vPOS ref, the syncer's whmcs-paid-keyed dedup
        // won't recognise this row — harmless for the cash-term vPOS case (the
        // syncer skips cash-term entirely), and a narrow, accepted edge for a
        // credit-term invoice paid at issue that is later refunded (see BACKLOG).
        $txnId = $ref ?? WhmcsPaidReceipt::transactionKey($whmcsInvoiceId);

        return DB::transaction(function () use ($invoice, $payload, $txnId, $gateway, $whmcsInvoiceId): float {
            $locked = Invoice::query()->whereKey($invoice->id)->lockForUpdate()->first();
            if ($locked === null) {
                return 0.0;
            }

            // Dedup, re-checked under the lock — two independent guards:
            //  (a) our own receipt for this WHMCS payment already exists, keyed on
            //      the id we're about to write. withTrashed → once recorded, NEVER
            //      resurrect it: an operator who DELETED (or refunded, netting to 0)
            //      the auto-receipt meant it, so a re-run must not re-add it; OR
            //  (b) ANY real payment already on the invoice (a manual entry / the
            //      syncer) — never stack an auto-receipt on top of it.
            if (Payment::withTrashed()->where('invoice_id', $locked->id)->where('transaction_id', $txnId)->exists()) {
                return 0.0;
            }
            $paidSoFar = round((float) DB::table('payments')
                ->where('invoice_id', $locked->id)
                ->whereNull('deleted_at')
                ->selectRaw('COALESCE(SUM('.Payment::NET_AMOUNT_SQL.'), 0) AS net')
                ->value('net'), 2);
            if ($paidSoFar > self::EPS) {
                return 0.0;
            }

            // The collectible still owed — the same figure the invoice's «Πληρωμές»
            // tab records (NOT the synthetic cash-term balance, which is 0 at issue).
            $owed = round((float) $locked->balanceData()->owed, 2);
            if ($owed <= self::EPS) {
                return 0.0;
            }

            Payment::create([
                'company_id' => $locked->company_id,
                'customer_id' => $locked->customer_id,
                'invoice_id' => $locked->id,
                'kind' => 'payment',
                // The WHMCS gateway already resolved to this invoice's payment method
                // during mapping — inherit it so the receipt's «Τρόπος» matches.
                'payment_method_id' => $locked->payment_method_id,
                'amount' => $owed,
                'pay_date' => WhmcsPaidReceipt::payDate($payload),
                'transaction_id' => $txnId,
                'notes' => self::note($whmcsInvoiceId, $gateway),
            ]);

            return $owed;
        });
    }

    /**
     * Gateway name + acquirer/vPOS ref from the WHMCS payload, for the note. Reads
     * the largest incoming transaction; falls back to the invoice-level
     * `paymentmethod` for the gateway and no ref when the payload carries no
     * transaction detail (shape varies native vs bridge).
     *
     * @param  array<string, mixed>  $payload
     * @return array{0: ?string, 1: ?string} [ref, gateway]
     */
    private static function provenance(array $payload): array
    {
        $gateway = ($g = trim((string) ($payload['paymentmethod'] ?? ''))) !== '' ? $g : null;

        $txns = $payload['transactions']['transaction'] ?? null;
        if (is_array($txns)) {
            // WHMCS returns one transaction as a bare map, many as a list — normalise.
            $rows = array_is_list($txns) ? $txns : [$txns];
            $best = null;
            foreach ($rows as $row) {
                // Only INCOMING money (amountin > 0) — never label the receipt with a
                // refund/adjustment (amountout) transaction's id.
                if (is_array($row) && (float) ($row['amountin'] ?? 0) > 0
                    && ($best === null || (float) ($row['amountin'] ?? 0) > (float) ($best['amountin'] ?? 0))) {
                    $best = $row;
                }
            }
            if (is_array($best)) {
                if (($bg = trim((string) ($best['gateway'] ?? ''))) !== '') {
                    $gateway = $bg;
                }
                $ref = trim((string) ($best['transid'] ?? ''));

                return [$ref !== '' ? $ref : null, $gateway];
            }
        }

        return [null, $gateway];
    }

    /**
     * Human-readable trail for the «Σημείωση» column — the WHMCS invoice id and the
     * gateway (the real acquirer/vPOS ref is the receipt's transaction_id).
     */
    private static function note(int $whmcsInvoiceId, ?string $gateway): string
    {
        return 'Είσπραξη από WHMCS #'.$whmcsInvoiceId
            .($gateway !== null && $gateway !== '' ? ' · '.$gateway : '')
            .' (αυτόματη καταγραφή).';
    }
}
