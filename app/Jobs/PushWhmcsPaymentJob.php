<?php

namespace App\Jobs;

use App\Models\Invoice;
use App\Services\Whmcs\PaymentPushResult;
use App\Services\Whmcs\WhmcsInvoiceFetcher;
use App\Services\Whmcs\WhmcsPaymentPusher;
use App\Services\Whmcs\WhmcsPaymentPusherFactory;
use App\Support\Whmcs\WhmcsPaymentSyncCache;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Auto-push (opt-in): when an ekdosi επί-πιστώσει invoice is settled locally,
 * tell the customer's WHMCS to mark its invoice Paid. Queued so the WHMCS HTTP
 * write never blocks the operator recording the payment. All safety lives in
 * WhmcsPaymentPusher (opt-in / idempotent / anti-echo / live-only) — this job
 * just resolves the tenant's fetch + push closures and delegates, so a
 * not-yet-eligible payment is simply a no-op.
 *
 * Carries only the invoice id (reloaded in handle) so a deleted/edited invoice
 * can't act on stale serialized state.
 */
class PushWhmcsPaymentJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public function __construct(public readonly int $invoiceId) {}

    public function handle(
        WhmcsInvoiceFetcher $fetcher,
        WhmcsPaymentPusherFactory $pushers,
        WhmcsPaymentPusher $pusher,
    ): void {
        $invoice = Invoice::find($this->invoiceId);
        if ($invoice === null || $invoice->company === null) {
            return;
        }

        $fetch = $fetcher->for($invoice->company);
        $push = $pushers->for($invoice->company);
        if ($fetch === null || $push === null) {
            return;   // WHMCS not configured — nothing to push to
        }

        $result = $pusher->push($invoice, $fetch, $push);

        // Settled at WHMCS → drop it from the outbound worklist so the console
        // list / tile don't linger until the next reconcile.
        if ($result === PaymentPushResult::Pushed || $result === PaymentPushResult::AlreadyPaid) {
            WhmcsPaymentSyncCache::removeOutbound($invoice->company, (int) $invoice->id);
        }
    }
}
