<?php

namespace App\Services\WhmcsInbox;

use App\Models\Invoice;
use App\Models\Payment;
use App\Models\PendingWhmcsInvoice;
use Illuminate\Support\Carbon;
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
 *  - Records the COLLECTIBLE still owed (payable − credited), NOT the synthetic
 *    cash-term balance (which is 0 at issue) — so the invoice flips from
 *    settled-at-issue to real tracking and nets back to zero (same reason the
 *    invoice «Πληρωμές» tab records owed, not balance).
 *  - Idempotent: skips if the invoice already carries any real payment (net > 0),
 *    so a re-issue never doubles; and since a full receipt drives the balance to
 *    0, the only-if-open WhmcsPaymentSyncer stays a no-op on it too.
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
        if (! is_array($payload) || strcasecmp((string) ($payload['status'] ?? ''), 'Paid') !== 0) {
            return 0.0;
        }

        if ($invoice->customer_id === null
            || $invoice->local_status === 'cancelled'
            || $invoice->mydata_state === 'CANCELLED'
            || $invoice->isCreditNote()) {
            return 0.0;
        }

        return DB::transaction(function () use ($pending, $invoice, $payload): float {
            $locked = Invoice::query()->whereKey($invoice->id)->lockForUpdate()->first();
            if ($locked === null) {
                return 0.0;
            }

            // Idempotency: any real recorded payment (net of refunds) means this is
            // already handled (a re-issue, a manual entry, or the syncer) — never
            // stack a second auto-receipt on top.
            $paidSoFar = round((float) DB::table('payments')
                ->where('invoice_id', $locked->id)
                ->whereNull('deleted_at')
                ->selectRaw('COALESCE(SUM('.Payment::NET_AMOUNT_SQL.'), 0) AS net')
                ->value('net'), 2);
            if ($paidSoFar > self::EPS) {
                return 0.0;
            }

            // The collectible still to record = payable − credited (a fresh invoice
            // → the full payable). Uses payableTotal(), NOT the synthetic cash-term
            // balance, which is 0 at issue.
            $credited = round((float) $locked->balanceData()->credited, 2);
            $toRecord = round($locked->payableTotal() - $credited, 2);
            if ($toRecord <= self::EPS) {
                return 0.0;
            }

            [$txnId, $gateway] = self::provenance($payload, (int) $pending->whmcs_invoice_id);

            Payment::create([
                'company_id' => $locked->company_id,
                'customer_id' => $locked->customer_id,
                'invoice_id' => $locked->id,
                'kind' => 'payment',
                // The WHMCS gateway already resolved to this invoice's payment method
                // during mapping — inherit it so the receipt's «Τρόπος» matches.
                'payment_method_id' => $locked->payment_method_id,
                'amount' => $toRecord,
                'pay_date' => self::payDate($payload),
                'transaction_id' => $txnId,
                'notes' => self::note((int) $pending->whmcs_invoice_id, $gateway),
            ]);

            return $toRecord;
        });
    }

    /**
     * The receipt's provenance from the WHMCS payload: the acquirer transaction id
     * (the real vPOS/gateway ref, shown in «Κωδ. συναλλαγής») and the gateway name.
     * Reads the largest incoming transaction; falls back to a deterministic
     * `whmcs-paid:{id}` id and the invoice-level `paymentmethod` when the payload
     * carries no transaction detail (shape varies native vs bridge).
     *
     * @param  array<string, mixed>  $payload
     * @return array{0: string, 1: ?string} [transactionId, gateway]
     */
    private static function provenance(array $payload, int $whmcsInvoiceId): array
    {
        $gateway = ($g = trim((string) ($payload['paymentmethod'] ?? ''))) !== '' ? $g : null;

        $txns = $payload['transactions']['transaction'] ?? null;
        if (is_array($txns)) {
            // WHMCS returns one transaction as a bare map, many as a list — normalise.
            $rows = array_is_list($txns) ? $txns : [$txns];
            $best = null;
            foreach ($rows as $row) {
                if (is_array($row) && ($best === null || (float) ($row['amountin'] ?? 0) > (float) ($best['amountin'] ?? 0))) {
                    $best = $row;
                }
            }
            if (is_array($best)) {
                if (($bg = trim((string) ($best['gateway'] ?? ''))) !== '') {
                    $gateway = $bg;
                }
                if (($id = trim((string) ($best['transid'] ?? ''))) !== '') {
                    return [$id, $gateway];
                }
            }
        }

        return ['whmcs-paid:'.$whmcsInvoiceId, $gateway];
    }

    /** Human-readable trail for the «Σημείωση» column. */
    private static function note(int $whmcsInvoiceId, ?string $gateway): string
    {
        return 'Είσπραξη από WHMCS #'.$whmcsInvoiceId
            .($gateway !== null && $gateway !== '' ? ' · '.$gateway : '')
            .' (αυτόματη καταγραφή).';
    }

    /**
     * WHMCS `datepaid` (Y-m-d part) when present + real; else today. Guards the
     * WHMCS zero-date sentinel ('0000-00-00 …'). Mirrors WhmcsPaymentSyncer.
     *
     * @param  array<string, mixed>  $payload
     */
    private static function payDate(array $payload): string
    {
        $date = substr((string) ($payload['datepaid'] ?? ''), 0, 10);
        if ($date === '' || str_starts_with($date, '0000')) {
            return Carbon::now()->toDateString();
        }

        return $date;
    }
}
