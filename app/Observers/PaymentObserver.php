<?php

namespace App\Observers;

use App\Jobs\PushWhmcsPaymentJob;
use App\Models\Company;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\PendingWhmcsInvoice;
use App\Services\InvoiceBalance;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Keeps invoices.{paid_total,payment_status} in sync whenever a payment
 * allocated to an invoice is created / changed / (soft-)deleted /
 * restored. On-account payments (invoice_id = null) affect only the
 * customer-level balance (computed live in the ledger), so they're
 * skipped here. Mirrors the legacy MARK_AI0-style denormalisation.
 */
class PaymentObserver
{
    public function __construct(private readonly InvoiceBalance $balance) {}

    public function created(Payment $payment): void
    {
        $this->recompute($payment->invoice_id);
        $this->maybePushToWhmcs($payment);
    }

    public function updated(Payment $payment): void
    {
        $this->recompute($payment->invoice_id);

        // Re-allocated to a different invoice → refresh the old one too.
        $original = $payment->getOriginal('invoice_id');
        if ($original !== null && $original !== $payment->invoice_id) {
            $this->recompute($original);
        }
        $this->maybePushToWhmcs($payment);
    }

    public function deleted(Payment $payment): void
    {
        $this->recompute($payment->invoice_id);
    }

    public function restored(Payment $payment): void
    {
        $this->recompute($payment->invoice_id);
    }

    public function forceDeleted(Payment $payment): void
    {
        $this->recompute($payment->invoice_id);
    }

    private function recompute(?int $invoiceId): void
    {
        if ($invoiceId === null) {
            return;
        }

        $invoice = Invoice::find($invoiceId);
        if (! $invoice) {
            return;
        }

        DB::transaction(fn () => $this->balance->recompute($invoice));
    }

    /**
     * Auto-push (opt-in) the settlement to WHMCS. Cheap pre-checks here — a
     * real (non-inbound) invoice-allocated payment on an opted-in tenant whose
     * invoice now carries a WHMCS link; the JOB (via WhmcsPaymentPusher) does
     * the full eligibility + the idempotent write. Dispatched afterCommit so it
     * never fires inside the payment's own transaction, and so a rolled-back
     * payment never pushes. Inbound-origin rows (whmcs-paid:*) are the anti-echo
     * cut — they were paid FROM WHMCS, never pushed back.
     */
    private function maybePushToWhmcs(Payment $payment): void
    {
        if ($payment->invoice_id === null
            || $payment->kind !== 'payment'
            || Str::startsWith((string) $payment->transaction_id, 'whmcs-paid:')) {
            return;
        }

        $invoiceId = $payment->invoice_id;
        $companyId = $payment->company_id;

        DB::afterCommit(function () use ($invoiceId, $companyId): void {
            $hasLink = PendingWhmcsInvoice::query()
                ->where('company_id', $companyId)
                ->where('invoice_id', $invoiceId)
                ->where('status', PendingWhmcsInvoice::STATUS_FILED)
                ->whereNotNull('whmcs_invoice_id')
                ->whereNull('whmcs_payment_pushed_at')
                ->exists();
            if (! $hasLink) {
                return;
            }

            $tenant = Company::find($companyId);
            if ($tenant === null || ! (bool) $tenant->whmcs_push_payments) {
                return;   // outbound not opted in
            }

            // Only a SETTLED receivable is a mark-paid to push — a partial
            // payment shouldn't spend a WHMCS call just to be skipped by the job.
            $invoice = Invoice::find($invoiceId);
            if ($invoice === null || $this->balance->for($invoice)->balance > 0.005) {
                return;
            }

            PushWhmcsPaymentJob::dispatch($invoiceId);
        });
    }
}
