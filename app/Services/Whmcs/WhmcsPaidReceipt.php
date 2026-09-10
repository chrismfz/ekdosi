<?php

namespace App\Services\Whmcs;

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
     * WHMCS `datepaid` (Y-m-d part) when present + real; else today. Guards the
     * WHMCS zero-date sentinel ('0000-00-00 …') which is not a valid date.
     *
     * @param  array<string, mixed>  $payload
     */
    public static function payDate(array $payload): string
    {
        $date = substr((string) ($payload['datepaid'] ?? ''), 0, 10);
        if ($date === '' || str_starts_with($date, '0000')) {
            return Carbon::now()->toDateString();
        }

        return $date;
    }
}
