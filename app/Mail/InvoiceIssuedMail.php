<?php

namespace App\Mail;

use App\Models\Invoice;
use App\Services\MailTemplateRenderer;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * The "we've issued an invoice for you" customer email — port of the
 * legacy FMailInvoices.cpp:106 flow. PDF is built at send-time (NOT
 * constructed-time) so the queue payload stays small (a model ID,
 * not a 100KB byte string).
 *
 * Per-tenant configurable bits (PR #27):
 *   - Subject + body: rendered from companies.mail_subject_template /
 *     mail_body_template via MailTemplateRenderer (str_replace on
 *     curated placeholders — NO Blade evaluation of operator input).
 *     Defaults to a sensible Greek template if blank.
 *   - From: tenant.mail_from_address + mail_from_name, falls back to
 *     config('mail.from').
 *   - Cc: customer.secondary_email when present (legacy "accountant
 *     copy").
 *   - Bcc: tenant.invoice_audit_bcc parsed list — replaces the
 *     legacy hardcoded invoice@myip.gr.
 *   - SMTP transport: SendInvoiceEmail builds the mailer via
 *     TenantMailerFactory before send, so this Mailable is transport-
 *     agnostic; the mailer instance handed by Mail::mailer() is the
 *     per-tenant one.
 *
 * Markdown view because it's the standard Laravel mail layout —
 * both HTML and plain-text auto-rendered, no separate templates to
 * keep in sync. The Markdown view receives the already-interpolated
 * subject + body strings; it just wraps them with the standard
 * Laravel mail chrome (header / footer / styling).
 */
class InvoiceIssuedMail extends Mailable implements ShouldQueue
{
    use Queueable;
    use SerializesModels;

    /**
     * Bytes of the rendered PDF, passed in by the dispatcher so the
     * queue payload stays small (the Invoice model is the only thing
     * in SerializesModels — the renderer runs in the job handler,
     * not at construct time).
     */
    public string $pdfBytes;

    public function __construct(
        public Invoice $invoice,
        string $pdfBytes,
    ) {
        $this->pdfBytes = $pdfBytes;
    }

    public function envelope(): Envelope
    {
        $tenant = $this->invoice->company;
        $customer = $this->invoice->customer;
        $renderer = app(MailTemplateRenderer::class);

        $fromAddr = $tenant->mail_from_address ?: config('mail.from.address');
        $fromName = $tenant->mail_from_name    ?: ($tenant->name ?: config('mail.from.name'));

        $cc = [];
        if ($customer?->secondary_email) {
            $cc[] = new Address($customer->secondary_email);
        }

        $bcc = [];
        foreach ($tenant->auditBccList() as $bccAddr) {
            $bcc[] = new Address($bccAddr);
        }

        return new Envelope(
            from: new Address($fromAddr, $fromName),
            cc: $cc,
            bcc: $bcc,
            subject: $renderer->renderSubject($this->invoice, $tenant->mail_subject_template),
        );
    }

    public function content(): Content
    {
        $renderer = app(MailTemplateRenderer::class);

        return new Content(
            markdown: 'mail.invoice.issued',
            with: [
                'tenant' => $this->invoice->company,
                // Pre-interpolated body string. The Blade view {{ }}-
                // escapes it on output, so any HTML/Blade syntax an
                // operator put in the template is rendered as plain
                // text — defending against operator-edited HTML being
                // executed.
                'body' => $renderer->renderBody($this->invoice, $this->invoice->company?->mail_body_template),
            ],
        );
    }

    /**
     * @return array<int, Attachment>
     */
    public function attachments(): array
    {
        return [
            Attachment::fromData(fn () => $this->pdfBytes, $this->filename())
                ->withMime('application/pdf'),
        ];
    }

    private function filename(): string
    {
        return 'invoice-'.preg_replace('/[^A-Za-z0-9_-]/', '', $this->invoice->invcode ?? 'document').'.pdf';
    }
}
