<?php

namespace App\Services\Whmcs;

use App\Models\Payment;
use App\Models\PendingWhmcsInvoice;
use App\Support\Billing\MycsPayload;
use Illuminate\Support\Carbon;

/**
 * Shared conventions for turning a WHMCS invoice payload into an ekdosi payment.
 * ONE definition used by BOTH inbound money paths so their dedup can never drift:
 *
 *  - WhmcsPaymentSyncer (WHMCS → ekdosi, closes OPEN credit-term receivables later);
 *  - WhmcsInbox\WhmcsReceiptRecorder (records the receipt at ISSUE for invoices
 *    already paid in WHMCS — the cash-term / vPOS case the syncer skips).
 *
 * The `transactionKey()` is the load-bearing bit: both paths stamp/dedup on the
 * SAME deterministic id, so whichever records first, the other sees it and never
 * double-records — even if the invoice balance later reopens (a refund).
 */
final class WhmcsPaidReceipt
{
    /** True when the WHMCS snapshot marks the invoice fully Paid. */
    public static function isPaid(mixed $payload): bool
    {
        return is_array($payload) && strcasecmp((string) ($payload['status'] ?? ''), 'Paid') === 0;
    }

    /**
     * The deterministic dedup id both paths key on, one per WHMCS invoice. Kept in
     * ONE place so a change can't desync the syncer from the recorder.
     */
    public static function transactionKey(int $whmcsInvoiceId): string
    {
        return 'whmcs-paid:'.$whmcsInvoiceId;
    }

    /**
     * Does ekdosi already hold THIS WHMCS settlement? Matches our receipt by the
     * deterministic `whmcs-paid:{id}` key AND — once the payload is known — by the
     * real acquirer/vPOS ref the issue-time recorder stamps instead (provenance()).
     *
     *  - a LIVE receipt → yes, always (never double-record);
     *  - a soft-DELETED one → only for the settlement it mirrored, i.e. one WHMCS
     *    already reported when that receipt was CREATED (`datepaid` ≤ its
     *    `created_at` — a system timestamp, unlike the editable pay_date). The
     *    operator who deleted it meant «this payment did not happen» (an invoice
     *    marked Paid in WHMCS with no money in, just to get it issued), so a sweep
     *    must not resurrect it. Once WHMCS is unwound (Mark Unpaid) and the REAL
     *    money is entered, its `datepaid` is later → a new settlement → it records.
     *    Without a usable `datepaid` (or before the fetch, `$payload` null) a deleted
     *    receipt keeps blocking — the conservative side; so does a real payment
     *    back-dated in WHMCS to before the bogus receipt, or entered within
     *    CLOCK_GRACE_HOURS after it (record those by hand — «Νέα είσπραξη»).
     *
     * @param  array<string, mixed>|null  $payload
     */
    public static function alreadyHeld(int $companyId, int $whmcsInvoiceId, int $invoiceId, ?array $payload): bool
    {
        $key = self::transactionKey($whmcsInvoiceId);
        $ref = $payload === null ? null : self::provenance($payload)[0];

        $rows = Payment::withTrashed()
            ->where('company_id', $companyId)
            ->where(function ($q) use ($key, $ref, $invoiceId, $whmcsInvoiceId) {
                $q->where('transaction_id', $key);
                // The WHMCS transid is free text (an operator may type «ΕΜΒΑΣΜΑ» on
                // every bank transfer) → match it ONLY on this invoice AND only the
                // recorder's own auto-receipt for THIS WHMCS invoice (its note), never
                // an operator's manual payment that happens to carry the same text.
                if ($ref !== null) {
                    $q->orWhere(fn ($r) => $r->where('transaction_id', $ref)
                        ->where('invoice_id', $invoiceId)
                        ->where('notes', 'like', 'Είσπραξη από WHMCS #'.$whmcsInvoiceId.'%'));
                }
            })
            ->get(['id', 'created_at', 'deleted_at']);

        if ($rows->contains(fn (Payment $p) => $p->deleted_at === null)) {
            return true;
        }
        if ($rows->isEmpty()) {
            return false;
        }

        $paidAt = $payload === null ? null : self::paidAt($payload);
        if ($paidAt === null) {
            return true;
        }

        return $rows->contains(fn (Payment $p) => $p->created_at === null
            || $paidAt->lte($p->created_at->copy()->addHours(self::CLOCK_GRACE_HOURS)));
    }

    /**
     * Is THIS WHMCS «Paid» not money, so the payment sync must neither record nor list it?
     *
     *  - the customer is flagged «Πληρωμένο στο WHMCS ≠ εισπραγμένο» (`customers.whmcs_paid_on_credit`, on the billed
     *    customer or the WHMCS client's): their WHMCS «Paid» is never taken for a payment automatically — whatever
     *    the timeline (issued Unpaid first and marked Paid later only to stop a suspension, included). Staff record
     *    their payments by hand («Νέα είσπραξη»);
     *  - or MyCS reports this «Paid» short (`mycs.balance` > 0 — marked Paid by hand without all the money);
     *  - or the operator issued this row ΕΠΙ ΠΙΣΤΩΣΕΙ with «Δεν έχει πληρωθεί ακόμα» (`whmcs_paid_waived_at`): the
     *    settlement WHMCS already reported then (`datepaid` ≤ waived_at + grace) is the waived one; a LATER one (WHMCS
     *    unwound and really paid) is money and syncs. No usable `datepaid` → waived (the conservative side). A real
     *    payment back-dated in WHMCS to before the waiver, or entered within the grace, stays open for a human.
     *
     * Either way the invoice stays an open receivable — visible, never silently settled.
     *
     * @param  array<string, mixed>|null  $payload
     */
    public static function isWaived(PendingWhmcsInvoice $row, ?array $payload): bool
    {
        if ($row->customer?->whmcs_paid_on_credit || $row->invoice?->customer?->whmcs_paid_on_credit) {
            return true;
        }
        // MyCS says its «Paid» is short (`mycs.balance` > 0): not a full settlement to close the receivable with.
        if (MycsPayload::shortfall($payload) > 0.005) {
            return true;
        }
        // It WAS short when it came in and MyCS now reports it settled: the rest has really been paid since (MyCS
        // keeps its first paid_at, so the datepaid rule below would wrongly call it the waived settlement).
        if (MycsPayload::is($payload) && MycsPayload::shortfall(is_array($row->payload) ? $row->payload : null) > 0.005) {
            return false;
        }
        $waivedAt = $row->whmcs_paid_waived_at;
        if ($waivedAt === null) {
            return false;
        }
        $paidAt = $payload === null ? null : self::paidAt($payload);

        return $paidAt === null || $paidAt->lte($waivedAt->copy()->addHours(self::CLOCK_GRACE_HOURS));
    }

    /**
     * `datepaid` is WHMCS's local clock, `created_at` ours. Both are Europe/Athens
     * today; the grace absorbs a WHMCS host left on UTC (±2-3h) so a bogus settlement
     * can never look «newer» than its own receipt. Cost: a real payment entered in
     * WHMCS within this window after the bogus receipt is not auto-synced — the
     * invoice stays open and visible, the operator records it by hand (safe side).
     */
    private const CLOCK_GRACE_HOURS = 3;

    /**
     * Gateway name + acquirer/vPOS ref from the WHMCS payload — the SAME ref the
     * issue-time WhmcsReceiptRecorder stamps as transaction_id (it calls this), so
     * the syncer's dedup recognises that receipt too. Reads the largest
     * INCOMING transaction (never a refund/adjustment); falls back to the invoice-
     * level `paymentmethod` for the gateway and no ref when the payload carries no
     * transaction detail (shape varies native vs bridge).
     *
     * @param  array<string, mixed>  $payload
     * @return array{0: ?string, 1: ?string} [ref, gateway]
     */
    public static function provenance(array $payload): array
    {
        $gateway = ($g = trim((string) ($payload['paymentmethod'] ?? ''))) !== '' ? $g : null;

        $txns = $payload['transactions']['transaction'] ?? null;
        if (is_array($txns)) {
            // WHMCS returns one transaction as a bare map, many as a list — normalise.
            $rows = array_is_list($txns) ? $txns : [$txns];
            $best = null;
            foreach ($rows as $row) {
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
     * The full WHMCS `datepaid` as a moment in the app timezone, or null when absent,
     * the zero-date sentinel, or unparseable.
     *
     * @param  array<string, mixed>  $payload
     */
    private static function paidAt(array $payload): ?Carbon
    {
        $raw = trim((string) ($payload['datepaid'] ?? ''));
        if ($raw === '' || str_starts_with($raw, '0000')) {
            return null;
        }
        try {
            return Carbon::parse($raw, config('app.timezone'));
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * WHMCS `datepaid` (Y-m-d part) when present + real; else today. Guards the
     * WHMCS zero-date sentinel ('0000-00-00 …') which is not a valid date.
     *
     * @param  array<string, mixed>  $payload
     */
    public static function payDate(array $payload): string
    {
        return self::realPaidDate($payload) ?? Carbon::now()->toDateString();
    }

    /**
     * WHMCS `datepaid` (Y-m-d) when present + real, else null (the zero-date sentinel).
     *
     * @param  array<string, mixed>  $payload
     */
    private static function realPaidDate(array $payload): ?string
    {
        $date = substr((string) ($payload['datepaid'] ?? ''), 0, 10);
        if ($date === '' || str_starts_with($date, '0000')) {
            return null;
        }

        return $date;
    }
}
