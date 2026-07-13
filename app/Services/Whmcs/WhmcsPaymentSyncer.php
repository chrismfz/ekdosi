<?php

namespace App\Services\Whmcs;

use App\Models\Company;
use App\Models\Payment;
use App\Models\PendingWhmcsInvoice;
use App\Services\InvoiceBalance;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Inbound payment sync (WHMCS → ekdosi). When a WHMCS invoice that we already
 * FILED on credit terms (an open receivable — the «τιμολόγιο πρώτα, πληρωμή
 * μετά» public-sector case) later gets marked Paid in WHMCS, close the ekdosi
 * receivable by recording a Payment for its outstanding balance.
 *
 * Money-write in EKDOSI only (never touches the customer's WHMCS). Safety:
 *  - **only-if-open**: skips any invoice whose balance is already ≤ 0, so a
 *    cash-term / already-settled invoice is never over-paid. This is also the
 *    natural idempotency — once recorded the balance is 0, so a re-run skips it.
 *  - **dedup by transaction_id** (`whmcs-paid:{id}`): belt-and-suspenders against
 *    recording the same WHMCS payment twice even if the invoice later reopens
 *    (e.g. a credit note is reversed).
 *  - records the exact OUTSTANDING BALANCE (WHMCS InvoicePaid = full settlement
 *    of that document), so it settles without over/under-paying.
 *  - cancelled invoices and credit notes are skipped.
 */
class WhmcsPaymentSyncer
{
    public function __construct(private readonly InvoiceBalance $balance) {}

    /**
     * @param  callable(int): (array<string, mixed>|null)  $fetchInvoice  Resolves a WHMCS
     *                                                                    invoice id to its payload (with 'status'/'datepaid') — native or bridge; null
     *                                                                    when WHMCS can't return it (skipped, never guessed as paid).
     */
    public function syncTenant(Company $tenant, callable $fetchInvoice): PaymentSyncResult
    {
        $checked = 0;
        $recorded = 0;
        $total = 0.0;

        $rows = PendingWhmcsInvoice::query()
            ->where('company_id', $tenant->id)
            ->where('status', PendingWhmcsInvoice::STATUS_FILED)
            ->whereNotNull('invoice_id')
            ->whereNotNull('whmcs_invoice_id')
            ->with('invoice')
            ->get();

        foreach ($rows as $row) {
            $invoice = $row->invoice;
            if ($invoice === null || $invoice->local_status === 'cancelled' || $invoice->isCreditNote()) {
                continue;
            }

            // only-if-open: never over-pay a settled/cash-term invoice.
            $balance = $this->balance->for($invoice)->balance;
            if ($balance <= 0.005) {
                continue;
            }

            $txnId = self::transactionId((int) $row->whmcs_invoice_id);
            if (Payment::query()->where('company_id', $tenant->id)->where('transaction_id', $txnId)->exists()) {
                continue;
            }

            $checked++;
            $payload = $fetchInvoice((int) $row->whmcs_invoice_id);
            if (! is_array($payload) || strcasecmp((string) ($payload['status'] ?? ''), 'Paid') !== 0) {
                continue;
            }

            DB::transaction(fn () => Payment::create([
                'company_id' => $tenant->id,
                'customer_id' => $invoice->customer_id,
                'invoice_id' => $invoice->id,
                'kind' => 'payment',
                'amount' => round($balance, 2),
                'pay_date' => self::payDate($payload),
                'transaction_id' => $txnId,
                'notes' => 'Αυτόματος συγχρονισμός πληρωμής από WHMCS #'.$row->whmcs_invoice_id,
            ]));

            $recorded++;
            $total += $balance;
        }

        return new PaymentSyncResult($checked, $recorded, round($total, 2));
    }

    private static function transactionId(int $whmcsInvoiceId): string
    {
        return 'whmcs-paid:'.$whmcsInvoiceId;
    }

    /**
     * WHMCS `datepaid` (Y-m-d part) when present + real; else today. Guards the
     * WHMCS zero-date sentinel ('0000-00-00 …') which is not a valid date.
     */
    private static function payDate(array $payload): string
    {
        $raw = (string) ($payload['datepaid'] ?? '');
        $date = substr($raw, 0, 10);
        if ($date === '' || str_starts_with($date, '0000')) {
            return Carbon::now()->toDateString();
        }

        return $date;
    }
}
