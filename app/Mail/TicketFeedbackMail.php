<?php

namespace App\Mail;

use App\Models\Ticket;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * Invites the customer to rate a just-closed ticket (Πυλώνας E, Phase 4 follow-up),
 * carrying a SIGNED link to the public rating page — no login needed. Sent FROM the
 * department mailbox so a reply still lands where the poller reads.
 */
class TicketFeedbackMail extends Mailable
{
    public function __construct(
        public Ticket $ticket,
        public string $url,
        public string $fromAddress,
        public string $fromName,
    ) {}

    public function envelope(): Envelope
    {
        $from = new Address($this->fromAddress, $this->fromName);

        return new Envelope(
            from: $from,
            replyTo: [$from],
            subject: '['.$this->ticket->reference.'] Πώς σας φάνηκε η εξυπηρέτηση;',
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'mail.ticket.feedback',
            text: 'mail.ticket.feedback_text',
            with: ['ticket' => $this->ticket, 'url' => $this->url],
        );
    }
}
