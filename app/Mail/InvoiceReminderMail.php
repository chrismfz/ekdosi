<?php

namespace App\Mail;

use App\Models\Invoice;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * A payment reminder for one document. Same chrome, sender, CC and audit BCC as
 * the invoice email (it reuses the `mail.invoice.issued` views, which render an
 * already-interpolated body with e() + nl2br — the escaping boundary for
 * operator-edited templates). The PDF is attached when the tenant asks for it
 * (an offered προτιμολόγιο renders with its «not a tax document» banner).
 */
class InvoiceReminderMail extends Mailable
{
    public function __construct(
        public Invoice $invoice,
        public string $subjectLine,
        public string $body,
        public string $bodyText,
        public ?string $pdfBytes = null,
    ) {}

    public function envelope(): Envelope
    {
        $tenant = $this->invoice->company;
        $customer = $this->invoice->customer;

        $cc = $customer?->secondary_email ? [new Address($customer->secondary_email)] : [];
        $bcc = array_map(static fn (string $a): Address => new Address($a), $tenant->auditBccList());

        return new Envelope(
            from: new Address(
                $tenant->mail_from_address ?: config('mail.from.address'),
                $tenant->mail_from_name ?: ($tenant->name ?: config('mail.from.name')),
            ),
            cc: $cc,
            bcc: $bcc,
            subject: $this->subjectLine,
        );
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'mail.invoice.issued',
            text: 'mail.invoice.issued_text',
            with: [
                'tenant' => $this->invoice->company,
                'body' => $this->body,
                'bodyText' => $this->bodyText,
            ],
        );
    }

    public function attachments(): array
    {
        if ($this->pdfBytes === null) {
            return [];
        }

        $code = preg_replace('/[^A-Za-z0-9_-]+/', '', (string) $this->invoice->invcode) ?: (string) $this->invoice->getKey();

        return [
            Attachment::fromData(fn () => $this->pdfBytes, "invoice-{$code}.pdf")->withMime('application/pdf'),
        ];
    }
}
