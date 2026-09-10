<?php

namespace App\Services\Whmcs;

use App\Models\Company;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\PendingWhmcsInvoice;
use App\Services\InvoiceBalance;
use Illuminate\Support\Facades\DB;
use Throwable;

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
 *  - **serialised write**: the balance re-read + dedup re-check + insert run under
 *    a `lockForUpdate` on the invoice, so two concurrent runs (scheduled + a
 *    manual `whmcs:sync-payments`) can't both pass the check and double-record —
 *    the second blocks, then sees the now-recorded payment / zero balance.
 *  - **dedup by transaction_id** (`whmcs-paid:{id}`): re-checked inside the lock,
 *    so the same WHMCS payment is never recorded twice even if the invoice later
 *    reopens (e.g. a credit note is reversed).
 *  - records the exact OUTSTANDING BALANCE (WHMCS InvoicePaid = full settlement
 *    of that document), read AFRESH under the lock, so a manual payment landing
 *    mid-run reduces (never over-pays) it.
 *  - cancelled (locally OR at AADE) invoices and credit notes are skipped.
 *  - one bad row (e.g. a malformed datepaid) is isolated — it can't abort the
 *    rest of the tenant's run.
 */
class WhmcsPaymentSyncer
{
    public function __construct(private readonly InvoiceBalance $balance) {}

    /**
     * Bulk sync every filed, WHMCS-linked, still-open invoice for a tenant
     * (scheduler + the inbox «Συγχρονισμός τώρα» action).
     *
     * @param  callable(int): (array<string, mixed>|null)  $fetchInvoice  Resolves a WHMCS
     *                                                                    invoice id to its payload (with 'status'/'datepaid') — native or bridge; null
     *                                                                    when WHMCS can't return it (skipped, never guessed as paid).
     */
    public function syncTenant(Company $tenant, callable $fetchInvoice): PaymentSyncResult
    {
        $checked = 0;
        $recorded = 0;
        $total = 0.0;

        // Eager-load the invoice AND its payment method — recordForRow reads
        // paymentMethod->due_days per row (the credit-term guard), so without
        // this it lazy-loads once per open receivable.
        $rows = PendingWhmcsInvoice::query()
            ->where('company_id', $tenant->id)
            ->where('status', PendingWhmcsInvoice::STATUS_FILED)
            ->whereNotNull('invoice_id')
            ->whereNotNull('whmcs_invoice_id')
            ->with('invoice.paymentMethod')
            ->get();

        foreach ($rows as $row) {
            try {
                $amount = $this->recordForRow($tenant, $row, $fetchInvoice);
                if ($amount === null) {
                    continue;   // skipped before hitting WHMCS (settled / dup / void)
                }
                $checked++;
                if ($amount > 0.005) {
                    $recorded++;
                    $total += $amount;
                }
            } catch (Throwable $e) {
                // Isolate a single bad row (e.g. a malformed WHMCS datepaid) so it
                // can't abort the rest of the tenant's open invoices.
                report($e);

                continue;
            }
        }

        return new PaymentSyncResult($checked, $recorded, round($total, 2));
    }

    /**
     * Sync ONE ekdosi invoice on demand (the per-invoice «Έχει πληρωθεί στο
     * WHMCS;» action). Returns the amount recorded (0.0 when nothing was — not
     * WHMCS-linked, already settled, or still unpaid at WHMCS).
     *
     * @param  callable(int): (array<string, mixed>|null)  $fetchInvoice
     */
    public function syncInvoice(Invoice $invoice, callable $fetchInvoice): float
    {
        $row = PendingWhmcsInvoice::query()
            ->where('company_id', $invoice->company_id)
            ->where('invoice_id', $invoice->id)
            ->where('status', PendingWhmcsInvoice::STATUS_FILED)
            ->whereNotNull('whmcs_invoice_id')
            ->with('invoice')
            ->first();

        if ($row === null) {
            return 0.0;
        }

        return max(0.0, (float) $this->recordForRow($invoice->company, $row, $fetchInvoice));
    }

    /**
     * The shared per-row unit. Returns: null = skipped before any WHMCS call
     * (invoice void/settled/dup); 0.0 = queried WHMCS but not Paid (or unreachable);
     * >0 = the amount recorded. The write is serialised under a `lockForUpdate` on
     * the invoice, re-reading balance + dedup INSIDE the lock; the HTTP fetch stays
     * OUTSIDE the transaction (never hold a row lock across a network call).
     *
     * @param  callable(int): (array<string, mixed>|null)  $fetchInvoice
     */
    private function recordForRow(Company $tenant, PendingWhmcsInvoice $row, callable $fetchInvoice): ?float
    {
        $invoice = $row->invoice;
        if ($invoice === null || ! $this->isLiveOpen($invoice)) {
            return null;
        }

        // Credit-term («επί πιστώσει») only — this sync exists to close OPEN
        // receivables. A cash-term invoice is settled at issue; if it carries a
        // residual balance (an operator logged a partial/deposit payment) that
        // is a deliberate money-trail, NOT a WHMCS receivable to auto-close.
        // Matches the per-invoice action's visibility gate (hasOpenWhmcsLink).
        if ((int) ($invoice->paymentMethod?->due_days ?? 0) <= 0) {
            return null;
        }

        // Cheap unlocked pre-filter — avoid the WHMCS call for settled rows.
        if ($this->balance->for($invoice)->balance <= 0.005) {
            return null;
        }
        $txnId = self::transactionId((int) $row->whmcs_invoice_id);
        if ($this->alreadyRecorded($tenant, $txnId)) {
            return null;
        }

        $payload = $fetchInvoice((int) $row->whmcs_invoice_id);
        if (! WhmcsPaidReceipt::isPaid($payload)) {
            return 0.0;
        }

        return DB::transaction(function () use ($tenant, $invoice, $txnId, $payload, $row): float {
            $locked = Invoice::query()->whereKey($invoice->id)->lockForUpdate()->first();
            if ($locked === null || ! $this->isLiveOpen($locked)) {
                return 0.0;
            }
            $balance = round($this->balance->for($locked)->balance, 2);
            if ($balance <= 0.005 || $this->alreadyRecorded($tenant, $txnId)) {
                return 0.0;
            }
            Payment::create([
                'company_id' => $tenant->id,
                'customer_id' => $locked->customer_id,
                'invoice_id' => $locked->id,
                'kind' => 'payment',
                'amount' => $balance,
                'pay_date' => self::payDate($payload),
                'transaction_id' => $txnId,
                'notes' => 'Αυτόματος συγχρονισμός πληρωμής από WHMCS #'.$row->whmcs_invoice_id,
            ]);

            return $balance;
        });
    }

    /**
     * A live, non-credit invoice that can carry a receivable: NOT cancelled
     * locally AND NOT cancelled at AADE (the canonical InvoiceScope::live()
     * predicate — a payment must never land on an AADE-void document), and not
     * a credit note.
     */
    private function isLiveOpen(Invoice $invoice): bool
    {
        return $invoice->local_status !== 'cancelled'
            && $invoice->mydata_state !== 'CANCELLED'
            && ! $invoice->isCreditNote();
    }

    private function alreadyRecorded(Company $tenant, string $txnId): bool
    {
        // withTrashed: dedup on the whmcs-paid id ONCE, ever — a receipt an operator
        // deliberately DELETED (soft) must not be resurrected on the next sweep.
        return Payment::withTrashed()
            ->where('company_id', $tenant->id)
            ->where('transaction_id', $txnId)
            ->exists();
    }

    private static function transactionId(int $whmcsInvoiceId): string
    {
        return WhmcsPaidReceipt::transactionKey($whmcsInvoiceId);
    }

    /** @param  array<string, mixed>  $payload */
    private static function payDate(array $payload): string
    {
        return WhmcsPaidReceipt::payDate($payload);
    }
}
