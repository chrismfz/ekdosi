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

    /**
     * The InvoiceMailLog row id created in handle(). Captured so
     * failed() (called by the queue worker after exhausting retries
     * OR after a thrown exception) can flip the row to terminal
     * 'failed' state — without it, a worker killed mid-send leaves
     * the row stuck on 'sending' forever, and a 3-retry exhausted job
     * leaves it on whatever state the last try wrote (often 'failed'
     * with a transient error message instead of "gave up after 3
     * tries").
     */
    public ?int $logId = null;

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
        $this->logId = $log->id;

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
     * when a non-retryable exception escapes handle(). Reconciles
     * the InvoiceMailLog row created at the top of handle():
     *
     *   - If handle() reached the catch block, the row is already
     *     'failed' with the last attempt's error — overwrite the
     *     message to make it clear retries are over.
     *   - If the worker was kill -9'd mid-send (or DI resolution
     *     threw before our catch), the row may still be 'queued' or
     *     'sending' — flip to 'failed' so the audit trail isn't a
     *     lie ("stuck on queued forever").
     *
     * No-op if logId is null (handle() never ran far enough to
     * persist a row — nothing to reconcile).
     */
    public function failed(Throwable $e): void
    {
        if ($this->logId === null) {
            return;
        }

        $log = InvoiceMailLog::find($this->logId);
        if ($log === null) {
            return;
        }

        // Don't overwrite a row that already cleanly transitioned to
        // 'sent' on an earlier successful attempt (defensive — failed()
        // shouldn't fire on success, but the queue contract permits
        // unusual call orders).
        if ($log->status === 'sent') {
            return;
        }

        $log->update([
            'status'        => 'failed',
            'error_message' => 'Gave up after '.$this->tries.' tries. Last error: '.$e->getMessage(),
            'failed_at'     => now(),
        ]);
    }
}
