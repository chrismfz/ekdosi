<?php

namespace App\Mail;

use App\Models\LeaveRequest;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * «Άδεια εγκρίθηκε / ακυρώθηκε» → the tenant's accountant (companies.
 * leave_notify_email), so every approved leave is declared exactly once
 * (ΕΡΓΑΝΗ «Οργάνωση Χρόνου Εργασίας – Άδειες»). Operator-side, Greek only
 * (the accountant is not a customer — no lang/ path needed).
 */
class LeaveAccountantMail extends Mailable
{
    /** @param 'approved'|'cancelled' $event */
    public function __construct(
        public LeaveRequest $leave,
        public string $event,
        public string $fromAddress,
        public string $fromName,
    ) {}

    public function envelope(): Envelope
    {
        $from = new Address($this->fromAddress, $this->fromName);
        $verb = $this->event === 'cancelled' ? 'ΑΚΥΡΩΣΗ άδειας' : 'Άδεια';

        return new Envelope(
            from: $from,
            replyTo: [$from],
            subject: sprintf(
                '%s: %s — %s (%s)',
                $verb,
                $this->leave->employee?->full_name,
                $this->leave->periodLabel(),
                $this->leave->type?->getLabel(),
            ),
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'mail.hr.leave-accountant',
            text: 'mail.hr.leave-accountant_text',
            with: ['leave' => $this->leave, 'event' => $this->event, 'companyName' => $this->fromName],
        );
    }
}
