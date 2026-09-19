<?php

namespace App\Mail;

use App\Models\Quote;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Quote-offer email with the PDF attached. Twin of InvoiceIssuedMail — same
 * per-tenant from-address / CC-secondary / BCC-audit envelope — but with a
 * self-contained subject/body (a quote is not a legal document, so it does
 * NOT go through the invoice-typed MailTemplateRenderer / MARK section).
 */
class QuoteOfferMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public Quote $quote,
        public string $pdfBytes,
    ) {}

    public function envelope(): Envelope
    {
        $tenant = $this->quote->company;
        $customer = $this->quote->customer;

        $fromAddr = $tenant->mail_from_address ?: config('mail.from.address');
        $fromName = $tenant->mail_from_name ?: ($tenant->name ?: config('mail.from.name'));

        $cc = [];
        if ($customer?->secondary_email) {
            $cc[] = new Address($customer->secondary_email);
        }

        $bcc = [];
        foreach ($tenant->auditBccList() as $bccAddr) {
            $bcc[] = new Address($bccAddr);
        }

        $label = __('mail.quote.subject_label');
        $subject = trim(($tenant->name ?: $label).' — '.$label.' '.$this->quote->code
            .($this->quote->subject ? ' · '.$this->quote->subject : ''));

        return new Envelope(
            from: new Address($fromAddr, $fromName),
            cc: $cc,
            bcc: $bcc,
            subject: $subject,
        );
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'mail.quote.offer',
            with: [
                'tenant' => $this->quote->company,
                'quote' => $this->quote,
            ],
        );
    }

    public function attachments(): array
    {
        return [
            Attachment::fromData(fn () => $this->pdfBytes, $this->filename())
                ->withMime('application/pdf'),
        ];
    }

    private function filename(): string
    {
        return 'quote-'.preg_replace('/[^A-Za-z0-9_-]/', '', $this->quote->code ?? 'offer').'.pdf';
    }
}
