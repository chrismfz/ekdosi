<?php

namespace App\Mail;

use App\Models\Ticket;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Mail\Mailables\Headers;

/**
 * An operator's reply, emailed to the customer (Πυλώνας E, Phase 3b-ii). Sent
 * FROM the department mailbox (the address the poller reads) so the customer's
 * reply lands back there, and carries the threading headers: our own Message-ID
 * (stored on the ticket message, so the customer's References point at it → the
 * inbound router threads the reply) and In-Reply-To/References to the message
 * being answered. The subject keeps the `[TK-…]` token as a threading fallback.
 */
class TicketReplyMail extends Mailable
{
    /**
     * @param  list<string>  $references  bare Message-IDs (no angle brackets)
     * @param  list<string>  $ccAddresses  CC-sourced watchers — VISIBLE Cc (they were
     *                                     openly on the customer's original thread)
     * @param  list<string>  $bccAddresses  manually-added watchers (already validated);
     *                                      Bcc, so the customer never sees the internal ones
     * @param  list<array{disk:string, path:string, name:string, mime:?string}>  $attachmentFiles
     *                                                                                             the operator reply's stored files (PR B), read from
     *                                                                                             the private disk at send time (see TicketAttachments)
     */
    public function __construct(
        public Ticket $ticket,
        public string $body,
        public string $fromAddress,
        public string $fromName,
        public string $messageId,
        public ?string $inReplyTo = null,
        public array $references = [],
        public array $ccAddresses = [],
        public array $bccAddresses = [],
        public array $attachmentFiles = [],
    ) {}

    public function envelope(): Envelope
    {
        $from = new Address($this->fromAddress, $this->fromName);

        return new Envelope(
            from: $from,
            replyTo: [$from],
            // CC-sourced watchers are visible (openly on the original thread); manual
            // watchers stay hidden in Bcc (never disclose internal staff addresses).
            cc: array_map(fn (string $address): Address => new Address($address), $this->ccAddresses),
            bcc: array_map(fn (string $address): Address => new Address($address), $this->bccAddresses),
            subject: '['.$this->ticket->reference.'] '.$this->ticket->subject,
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'mail.ticket.reply',
            text: 'mail.ticket.reply_text',
            with: ['ticket' => $this->ticket, 'body' => $this->body],
        );
    }

    public function headers(): Headers
    {
        return new Headers(
            messageId: $this->messageId,
            references: $this->references,
            text: $this->inReplyTo !== null ? ['In-Reply-To' => '<'.$this->inReplyTo.'>'] : [],
        );
    }

    /**
     * The operator reply's own attachments (PR B), streamed from the private disk.
     * The set is already size-budgeted by TicketAttachments::outboundPayload, so the
     * job never hands us a set that would make an undeliverable giant.
     *
     * @return list<Attachment>
     */
    public function attachments(): array
    {
        return array_map(
            fn (array $file): Attachment => Attachment::fromStorageDisk($file['disk'], $file['path'])
                ->as($file['name'])
                ->withMime($file['mime'] ?: 'application/octet-stream'),
            $this->attachmentFiles,
        );
    }
}
