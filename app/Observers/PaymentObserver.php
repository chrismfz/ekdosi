<?php

namespace App\Observers;

use App\Models\Invoice;
use App\Models\Payment;
use App\Services\InvoiceBalance;
use Illuminate\Support\Facades\DB;

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
    }

    public function updated(Payment $payment): void
    {
        $this->recompute($payment->invoice_id);

        // Re-allocated to a different invoice → refresh the old one too.
        $original = $payment->getOriginal('invoice_id');
        if ($original !== null && $original !== $payment->invoice_id) {
            $this->recompute($original);
        }
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
}
