<?php

namespace App\Jobs;

use App\Mail\QuoteOfferMail;
use App\Models\Quote;
use App\Models\QuoteMailLog;
use App\Services\QuotePdfRenderer;
use App\Services\TenantMailerFactory;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

/**
 * Sends a Προσφορά PDF to the customer by email, with a full audit trail in
 * quote_mail_logs. Twin of SendInvoiceEmail; retries with backoff.
 *
 * Trigger values: 'manual' (operator-clicked) — quotes have no auto path.
 */
class SendQuoteEmail implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public function __construct(
        public Quote $quote,
        public string $trigger = 'manual',
        public ?int $triggeredByUserId = null,
    ) {}

    public function handle(
        QuotePdfRenderer $renderer,
        TenantMailerFactory $mailerFactory,
    ): void {
        $quote = Quote::query()->whereKey($this->quote->getKey())
            ->with(['lines', 'customer', 'company'])
            ->first();

        if (! $quote) {
            return;
        }

        $tenant = $quote->company;
        $email = trim((string) ($quote->customer?->email ?? ''));

        $log = QuoteMailLog::create([
            'company_id' => $quote->company_id,
            'quote_id' => $quote->id,
            'recipient' => $email ?: '(no customer email)',
            'cc_list' => $quote->customer?->secondary_email ? [$quote->customer->secondary_email] : null,
            'bcc_list' => $tenant?->auditBccList() ?: null,
            'from_address' => $tenant?->mail_from_address ?: config('mail.from.address'),
            'subject' => 'Προσφορά '.$quote->code,
            'trigger' => $this->trigger,
            'status' => 'queued',
            'queued_at' => now(),
            'triggered_by_user_id' => $this->triggeredByUserId,
        ]);

        if ($email === '') {
            $log->update([
                'status' => 'failed',
                'error_message' => 'Ο πελάτης δεν έχει email — δεν είναι δυνατή η αποστολή.',
                'failed_at' => now(),
            ]);

            return;
        }

        try {
            $log->update(['status' => 'sending']);

            $pdfBytes = $renderer->render($quote);

            $mailerFactory->for($tenant)
                ->to($email)
                ->send(new QuoteOfferMail($quote, $pdfBytes));

            $log->update(['status' => 'sent', 'sent_at' => now()]);
        } catch (Throwable $e) {
            $log->update([
                'status' => 'failed',
                'error_message' => $e->getMessage(),
                'failed_at' => now(),
            ]);

            throw $e;
        }
    }

    public function backoff(): array
    {
        return [30, 120, 300];
    }

    public function failed(Throwable $e): void
    {
        $latest = QuoteMailLog::query()
            ->where('quote_id', $this->quote->getKey())
            ->where('trigger', $this->trigger)
            ->where('triggered_by_user_id', $this->triggeredByUserId)
            ->orderByDesc('id')
            ->first();

        if ($latest && $latest->status !== 'sent') {
            $latest->update([
                'status' => 'failed',
                'error_message' => 'Εγκατάλειψη μετά από '.$this->tries.' προσπάθειες. Τελευταίο σφάλμα: '.$e->getMessage(),
                'failed_at' => now(),
            ]);
        }
    }
}
