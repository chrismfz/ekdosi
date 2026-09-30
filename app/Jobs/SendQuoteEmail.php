<?php

namespace App\Jobs;

use App\Mail\QuoteOfferMail;
use App\Models\Quote;
use App\Models\QuoteMailLog;
use App\Services\Mail\PrimaryRecipientRejected;
use App\Services\Mail\RecipientFallbackSender;
use App\Services\QuotePdfRenderer;
use App\Services\TenantMailerFactory;
use App\Support\CustomerLanguage;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

/**
 * Sends a Προσφορά PDF to the customer (or the lead it was issued to) by email, with a full audit trail in
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
        RecipientFallbackSender $fallbackSender,
    ): void {
        $quote = Quote::query()->whereKey($this->quote->getKey())
            ->with(['lines', 'customer', 'lead', 'company'])
            ->first();

        if (! $quote) {
            return;
        }

        $tenant = $quote->company;
        // Customer email, or the lead's for a quote to a not-yet-customer.
        $email = (string) $quote->recipientEmail();

        $log = QuoteMailLog::create([
            'company_id' => $quote->company_id,
            'quote_id' => $quote->id,
            'recipient' => $email ?: '(no recipient email)',
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
                'error_message' => 'Ούτε ο πελάτης ούτε το lead έχει email — δεν είναι δυνατή η αποστολή.',
                'failed_at' => now(),
            ]);

            return;
        }

        try {
            $log->update(['status' => 'sending']);

            $pdfBytes = $renderer->render($quote);

            // i18n: stamp the recipient's language — the quote email subject +
            // body render in that language (both→en). PDF stays frozen.
            $locale = CustomerLanguage::forDocumentMail($quote);
            // A rejected Cc/Bcc must not stop the mail reaching the To.
            $dropped = $fallbackSender->send(
                $mailerFactory->for($tenant),
                $email,
                fn (bool $primaryOnly) => (new QuoteOfferMail($quote, $pdfBytes, $primaryOnly))->locale($locale),
            );

            $log->update(['status' => 'sent', 'sent_at' => now()] + RecipientFallbackSender::droppedLogFields($dropped));

            // The offer reached them: Draft → Sent, and a lead's quote becomes
            // a real contact on the lead (idempotent).
            $quote->markSent();
        } catch (PrimaryRecipientRejected $e) {
            // The To doesn't exist — no retries; alert so the email gets fixed.
            $log->update([
                'status' => 'failed',
                'error_message' => $e->getMessage(),
                'failed_at' => now(),
            ]);
            report($e);
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
