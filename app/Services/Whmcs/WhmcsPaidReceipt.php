<?php

namespace App\Services\Whmcs;

use App\Models\Payment;
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
     * Does ekdosi already hold THIS WHMCS settlement under `$txnId`?
     *
     *  - a LIVE receipt with the key → yes, always (never double-record);
     *  - a soft-DELETED one → only while WHMCS still reports the SAME settlement it
     *    was recorded from (same `datepaid` day). The operator who deleted it meant
     *    «this payment did not happen» — e.g. an invoice marked Paid in WHMCS with no
     *    money in, just to get it issued — so a sweep must not resurrect it. But once
     *    that is unwound in WHMCS (Mark Unpaid) and the REAL money is entered later,
     *    WHMCS reports a different `datepaid` → a new settlement → not held, it records.
     *    Without a usable `datepaid` (or before the fetch, `$payload` null) a deleted
     *    receipt keeps blocking — the conservative side.
     *
     * Known edge: fake-paid, deleted and really paid all on the SAME day still blocks
     * the sweep — the operator records that one by hand («Νέα είσπραξη»).
     *
     * @param  array<string, mixed>|null  $payload
     */
    public static function alreadyHeld(int $companyId, string $txnId, ?array $payload): bool
    {
        $rows = Payment::withTrashed()
            ->where('company_id', $companyId)
            ->where('transaction_id', $txnId)
            ->get(['id', 'pay_date', 'deleted_at']);

        if ($rows->contains(fn (Payment $p) => $p->deleted_at === null)) {
            return true;
        }
        if ($rows->isEmpty()) {
            return false;
        }

        $paidOn = $payload === null ? null : self::realPaidDate($payload);
        if ($paidOn === null) {
            return true;
        }

        return $rows->contains(fn (Payment $p) => $p->pay_date?->toDateString() === $paidOn);
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
