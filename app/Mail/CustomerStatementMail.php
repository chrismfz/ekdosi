<?php

namespace App\Mail;

use App\Models\Customer;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * "Here is your statement of account" customer email — the Καρτέλα
 * equivalent of InvoiceIssuedMail. Operator-initiated from the Καρτέλα
 * page. PDF bytes are passed in (rendered once by the action), keeping
 * this Mailable transport-agnostic; TenantMailerFactory supplies the
 * per-tenant mailer at send time.
 *
 * From / Bcc resolution mirrors InvoiceIssuedMail so audit-BCC and the
 * tenant's sending identity stay consistent across both mail types.
 *
 * NOT ShouldQueue: sent synchronously inside the Filament action so the
 * operator gets immediate success/failure feedback.
 */
class CustomerStatementMail extends Mailable
{
    public string $pdfBytes;

    public function __construct(
        public Customer $customer,
        string $pdfBytes,
        public ?string $bodyMessage = null,
        public ?string $subjectLine = null,
    ) {
        $this->pdfBytes = $pdfBytes;
    }

    public function envelope(): Envelope
    {
        $tenant = $this->customer->company;

        $fromAddr = $tenant?->mail_from_address ?: config('mail.from.address');
        $fromName = $tenant?->mail_from_name ?: ($tenant?->name ?: config('mail.from.name'));

        $bcc = [];
        foreach (($tenant?->auditBccList() ?? []) as $bccAddr) {
            $bcc[] = new Address($bccAddr);
        }

        $subject = $this->subjectLine
            ?: (($tenant?->name ? $tenant->name.' — ' : '').__('mail.statement.heading').': '.$this->customer->name);

        return new Envelope(
            from: new Address($fromAddr, $fromName),
            bcc: $bcc,
            subject: $subject,
        );
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'mail.customer.statement',
            with: [
                'customer' => $this->customer,
                'tenant' => $this->customer->company,
                'bodyMessage' => $this->bodyMessage,
            ],
        );
    }

    /**
     * @return array<int, Attachment>
     */
    public function attachments(): array
    {
        $slug = \App\Support\Filename::slug($this->customer->name, 'customer');

        return [
            Attachment::fromData(fn (): string => $this->pdfBytes, 'kartela-'.$slug.'.pdf')
                ->withMime('application/pdf'),
        ];
    }
}
