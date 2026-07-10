<?php

namespace App\Jobs;

use App\Mail\InvoiceIssuedMail;
use App\Models\Invoice;
use App\Models\InvoiceMailLog;
use App\Services\InvoicePdfRenderer;
use App\Services\MailTemplateRenderer;
use App\Services\TenantMailerFactory;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Renders the invoice PDF + dispatches InvoiceIssuedMail through the
 * tenant's mailer. Writes invoice_mail_log rows for the lifecycle so
 * operators can see send history without digging through queue logs.
 *
 * Triggers:
 *   - Auto: MyDataSubmitter::submit() on VALID, when tenant has
 *     auto_email_on_mydata_accept = true. trigger='auto', no user.
 *   - Manual: ViewInvoice "Resend email" header action. trigger=
 *     'manual', triggered_by_user_id set to the operator.
 *
 * Skip semantics: if the customer has no email we LOG + write a
 * 'failed' log row (with a clear error message) and return — the
 * job doesn't throw. A throw would push to failed_jobs and require
 * operator intervention for what's a "no email = nothing to send"
 * non-error.
 *
 * Tries: 3, with exponential backoff. Transient SMTP failures
 * typically recover; permanent ones (auth, bad address) flip to
 * 'failed' on attempt 3.
 */
class SendInvoiceEmail implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 3;

    /**
     * @return array<int, int>
     */
    public function backoff(): array
    {
        return [30, 120, 300];  // 30s, 2min, 5min
    }

    public function __construct(
        public Invoice $invoice,
        public string $trigger = 'auto',
        public ?int $triggeredByUserId = null,
    ) {}

    public function handle(
        InvoicePdfRenderer $renderer,
        TenantMailerFactory $mailerFactory,
        MailTemplateRenderer $templateRenderer,
    ): void {
        // Re-fetch with relations so we render + log against current
        // state, not the SerializesModels snapshot from queue time.
        $invoice = Invoice::query()->whereKey($this->invoice->getKey())
            ->with(['lines', 'invoiceType', 'customer', 'company', 'paymentMethod'])
            ->first();

        if (! $invoice) {
            Log::warning('SendInvoiceEmail: invoice no longer exists', [
                'invoice_id' => $this->invoice->getKey(),
            ]);
            return;
        }

        $tenant = $invoice->company;
        $email = trim((string) ($invoice->customer?->email ?? ''));

        // DOC-6: THE choke-point gate. Every dispatcher funnels through here —
        // the two UI actions (which also pre-gate for immediate feedback), the
        // finalize + myDATA-VALID auto paths, AND the invoices:resend-failed-emails
        // batch sweep. Only an ISSUED, non-cancelled document may go out: the mail
        // body (InvoiceIssuedMail) asserts «…που εκδόθηκε…», so a draft or a
        // now-cancelled invoice must not be sent — even if it was queued while
        // still active and cancelled before the worker ran (the TOCTOU the UI
        // gates can't close). We record the skip so operators see WHY nothing
        // was sent; we do NOT throw (a deliberate skip is not a job failure).
        if (! $invoice->isPubliclyViewable()) {
            InvoiceMailLog::create([
                'company_id'           => $invoice->company_id,
                'invoice_id'           => $invoice->id,
                'recipient'            => $email ?: '(no customer email)',
                'from_address'         => $tenant?->mail_from_address ?: config('mail.from.address'),
                'trigger'              => $this->trigger,
                'status'               => 'failed',
                'error_message'        => 'Το παραστατικό δεν είναι εκδοθέν (πρόχειρο ή ακυρωμένο) — δεν αποστέλλεται.',
                'queued_at'            => now(),
                'failed_at'            => now(),
                'triggered_by_user_id' => $this->triggeredByUserId,
            ]);
            Log::info('SendInvoiceEmail: invoice not issued (draft/cancelled) — skipping send', [
                'invoice_id'   => $invoice->getKey(),
                'invoice'      => $invoice->invcode,
                'local_status' => $invoice->local_status,
                'mydata_state' => $invoice->mydata_state,
            ]);

            return;
        }

        // Create the log row up-front in 'queued' state. Even the
        // "no email" path writes a row so operators see WHY nothing
        // was sent. We persist the id on $this so failed() can find
        // it if the worker exhausts retries.
        $log = InvoiceMailLog::create([
            'company_id'           => $invoice->company_id,
            'invoice_id'           => $invoice->id,
            'recipient'            => $email ?: '(no customer email)',
            'cc_list'              => $invoice->customer?->secondary_email
                ? [$invoice->customer->secondary_email]
                : null,
            'bcc_list'             => $tenant?->auditBccList() ?: null,
            'from_address'         => $tenant?->mail_from_address ?: config('mail.from.address'),
            'subject'              => $templateRenderer->renderSubject($invoice, $tenant?->mail_subject_template),
            'trigger'              => $this->trigger,
            'status'               => 'queued',
            'queued_at'            => now(),
            'triggered_by_user_id' => $this->triggeredByUserId,
        ]);

        if ($email === '') {
            $log->update([
                'status'        => 'failed',
                'error_message' => 'Customer has no email address — cannot send.',
                'failed_at'     => now(),
            ]);
            Log::info('SendInvoiceEmail: customer has no email — skipping send', [
                'invoice_id'  => $invoice->getKey(),
                'invoice'     => $invoice->invcode,
                'customer_id' => $invoice->customer_id,
            ]);
            return;
        }

        try {
            $log->update(['status' => 'sending']);

            $pdfBytes = $renderer->render($invoice);
            $mailerFactory->for($tenant)
                ->to($email)
                ->send(new InvoiceIssuedMail($invoice, $pdfBytes));

            $log->update([
                'status'  => 'sent',
                'sent_at' => now(),
            ]);
        } catch (Throwable $e) {
            $log->update([
                'status'        => 'failed',
                'error_message' => $e->getMessage(),
                'failed_at'     => now(),
            ]);
            // Re-throw so Laravel's retry / failed_jobs machinery picks
            // it up. Transient failures retry per $backoff; permanent
            // failures flip the log to 'failed' on each retry and
            // ultimately land in failed_jobs after attempt 3.
            throw $e;
        }
    }

    /**
     * Called by the queue worker when this job exhausts $tries OR
     * a non-retryable exception escapes handle(). Stamps the latest
     * log row for this invoice with a clear "gave up after N tries"
     * message so operators can distinguish a transient last-attempt
     * error from terminal exhaustion.
     *
     * Important: failed() runs on a FRESHLY DESERIALIZED job instance
     * (verified at vendor/laravel/framework/.../CallQueuedHandler.php
     * → unserialize(...) before invoking failed). Properties mutated
     * by handle() — like a captured log row id — are GONE by the time
     * failed() runs. So we look up the row by stable identifiers
     * available on the deserialized instance: invoice_id +
     * triggered_by_user_id, taking the most-recent. This is correct
     * because handle() creates exactly one row per attempt, all rows
     * for this invoice + trigger share a logical sequence, and we
     * want to reconcile the most recent regardless of its current
     * state (the catch block in handle() already wrote 'failed' with
     * the transient error; we overwrite with "gave up").
     *
     * Does NOT help the kill-9 / DI-threw scenarios — those skip
     * Laravel's failure pipeline entirely. Orphaned 'queued' or
     * 'sending' rows from those scenarios need an artisan sweeper;
     * CLAUDE.md tracks that as a deferred concern.
     */
    public function failed(Throwable $e): void
    {
        $latest = InvoiceMailLog::query()
            ->where('invoice_id', $this->invoice->getKey())
            ->where('trigger', $this->trigger)
            // Match on user attribution too — distinguishes a manual
            // re-send by operator B from an auto-dispatch attempt
            // running concurrently for the same invoice.
            ->where('triggered_by_user_id', $this->triggeredByUserId)
            ->orderByDesc('id')
            ->first();

        if ($latest === null) {
            return;
        }

        // Don't overwrite a clean 'sent' state — defensive; failed()
        // should never fire after a successful attempt, but the queue
        // contract permits unusual orderings under race conditions.
        if ($latest->status === 'sent') {
            return;
        }

        $latest->update([
            'status'        => 'failed',
            'error_message' => 'Gave up after '.$this->tries.' tries. Last error: '.$e->getMessage(),
            'failed_at'     => now(),
        ]);
    }
}
