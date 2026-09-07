<?php

namespace App\Mail;

use App\Models\Ticket;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
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
}
