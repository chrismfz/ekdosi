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
 *  - Idempotent + syncer-coordinated: stamps `transaction_id` with the SHARED
 *    `WhmcsPaidReceipt::transactionKey()` and skips if a row with that key already
 *    exists — so a re-issue never doubles AND the WhmcsPaymentSyncer (keyed on the
 *    same id) never double-records, even if a later refund reopens the balance.
 *    Also skips when ANY real payment is already on the invoice.
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
        $txnKey = WhmcsPaidReceipt::transactionKey($whmcsInvoiceId);

        return DB::transaction(function () use ($invoice, $payload, $txnKey, $whmcsInvoiceId): float {
            $locked = Invoice::query()->whereKey($invoice->id)->lockForUpdate()->first();
            if ($locked === null) {
                return 0.0;
            }

            // Dedup, re-checked under the lock — two independent guards:
            //  (a) our own receipt already recorded (keyed on the SHARED whmcs-paid
            //      id, so it holds even after a later refund nets payments to 0, and
            //      the WhmcsPaymentSyncer keyed on the same id won't double us).
            //      withTrashed: once recorded, NEVER resurrect it — an operator who
            //      DELETED the auto-receipt meant it (don't auto-re-add); OR
            //  (b) ANY real payment already on the invoice (a manual entry / the
            //      syncer) — never stack an auto-receipt on top of it.
            if (Payment::withTrashed()->where('invoice_id', $locked->id)->where('transaction_id', $txnKey)->exists()) {
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

            [$ref, $gateway] = self::provenance($payload);

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
                // Stable WHMCS key (shared with the syncer's dedup, above); the real
                // acquirer/vPOS ref lives in the note so both reach the Καρτέλα.
                'transaction_id' => $txnKey,
                'notes' => self::note($whmcsInvoiceId, $gateway, $ref),
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
     * Human-readable trail for the «Σημείωση» column — the WHMCS invoice id, the
     * gateway, and the real acquirer/vPOS ref (which the stable transaction_id key
     * doesn't carry).
     */
    private static function note(int $whmcsInvoiceId, ?string $gateway, ?string $ref): string
    {
        return 'Είσπραξη από WHMCS #'.$whmcsInvoiceId
            .($gateway !== null && $gateway !== '' ? ' · '.$gateway : '')
            .($ref !== null && $ref !== '' ? ' · ref '.$ref : '')
            .' (αυτόματη καταγραφή).';
    }
}
