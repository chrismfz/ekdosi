<?php

namespace App\Mail;

use App\Models\Invoice;
use App\Services\MailTemplateRenderer;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

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
 *
 * NOT ShouldQueue. The SendInvoiceEmail job IS the queuing layer;
 * making the Mailable ALSO ShouldQueue would cause Mailer::send() to
 * re-queue it (Illuminate\Mail\Mailer:349 detects the interface and
 * routes to ->queue() — verified at vendor source), which puts the
 * 50-500KB $pdfBytes string into a SECOND queue payload AND flips
 * the log row to 'sent' before the actual transport runs. Mailable
 * is plain so Mailer::send() sends synchronously inside the job
 * handler — single queue hop, accurate audit trail.
 */
class InvoiceIssuedMail extends Mailable
{
    /**
     * Bytes of the rendered PDF, passed in by the dispatcher so the
     * renderer runs once at job-handle time (not on every Mailable
     * instantiation).
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

        $template = $this->invoice->company?->mail_body_template;

        return new Content(
            markdown: 'mail.invoice.issued',
            // DOC-8 Finding A: an explicit plain-text view fed the UN-escaped
            // body. Without it Laravel auto-derives the text part from the same
            // markdown view, which carries the CommonMark backslash-escaping and
            // leaks literal `\.`/`\,` into text-only clients. renderText() does
            // NOT run CommonMark, so the text part needs no escaping.
            text: 'mail.invoice.issued_text',
            with: [
                'tenant' => $this->invoice->company,
                // Pre-interpolated body string (HTML part). The Blade view
                // {{ }}-escapes it on output, so any HTML/Blade syntax an
                // operator put in the template is rendered as plain text —
                // defending against operator-edited HTML being executed; the
                // markdown syntax is neutralised in renderBody() (DOC-8).
                'body' => $renderer->renderBody($this->invoice, $template),
                // Same body for the plain-text part, without markdown escaping.
                'bodyText' => $renderer->renderBodyPlain($this->invoice, $template),
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
