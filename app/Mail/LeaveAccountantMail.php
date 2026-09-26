<?php

namespace App\Mail;

use App\Models\LeaveRequest;
use App\Services\Ergani\ErganiPdf;
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
    /** @param 'approved'|'cancelled'|'declared' $event */
    public function __construct(
        public LeaveRequest $leave,
        public string $event,
        public string $fromAddress,
        public string $fromName,
    ) {}

    public function envelope(): Envelope
    {
        $from = new Address($this->fromAddress, $this->fromName);
        $verb = match ($this->event) {
            'cancelled' => 'ΑΚΥΡΩΣΗ άδειας',
            'declared' => 'Δήλωση ΕΡΓΑΝΗ άδειας',
            default => 'Άδεια',
        };

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

    /**
     * What the accountant must DO — depends on whether ekdosi already declared
     * (or withdrew) it in ΕΡΓΑΝΗ, and in which environment (trial = void).
     */
    public function intro(): string
    {
        $real = $this->leave->ergani_env === 'production';
        $status = $this->leave->ergani_status;

        if ($this->event === 'declared') {
            return 'Η παρακάτω άδεια δηλώθηκε ΤΩΡΑ αυτόματα στο ΕΡΓΑΝΗ από το ekdosi. ΑΝ την είχατε ήδη δηλώσει χειροκίνητα '
                .'(όπως σας είχαμε ζητήσει), ανακαλέστε τη ΜΙΑ από τις δύο δηλώσεις ή ενημερώστε μας.';
        }

        if ($this->event === 'cancelled') {
            return match (true) {
                $status === 'cancelled' && $real => 'Ανακλήθηκε η παρακάτω άδεια· η δήλωσή της στο ΕΡΓΑΝΗ ανακλήθηκε ήδη αυτόματα — για ενημέρωσή σας.',
                $status === 'cancel_failed' => 'Ανακλήθηκε η παρακάτω άδεια αλλά η αυτόματη ανάκληση στο ΕΡΓΑΝΗ ΑΠΕΤΥΧΕ — παρακαλούμε ανακαλέστε τη δήλωση.',
                $status === 'unknown' => 'Ανακλήθηκε η παρακάτω άδεια· η αυτόματη δήλωσή της στο ΕΡΓΑΝΗ είχε ΑΓΝΩΣΤΟ αποτέλεσμα — ελέγξτε αν καταχωρήθηκε και ανακαλέστε την.',
                default => 'Ακυρώθηκε η παρακάτω άδεια που είχε εγκριθεί — παρακαλούμε ενημερώστε/ανακαλέστε τη δήλωση στο ΕΡΓΑΝΗ αν είχε γίνει:',
            };
        }

        return match (true) {
            $status === 'submitted' && $real => 'Εγκρίθηκε η παρακάτω άδεια και δηλώθηκε ήδη αυτόματα στο ΕΡΓΑΝΗ — για ενημέρωσή σας, δεν χρειάζεται νέα δήλωση.',
            $status === 'failed' => 'Εγκρίθηκε η παρακάτω άδεια αλλά η αυτόματη δήλωση στο ΕΡΓΑΝΗ ΑΠΕΤΥΧΕ — παρακαλούμε για τη δήλωσή της:',
            $status === 'unknown' => 'Εγκρίθηκε η παρακάτω άδεια· η αυτόματη δήλωση στο ΕΡΓΑΝΗ έχει ΑΓΝΩΣΤΟ αποτέλεσμα — ελέγξτε αν καταχωρήθηκε πριν τη δηλώσετε:',
            default => 'Εγκρίθηκε η παρακάτω άδεια — παρακαλούμε για τη δήλωσή της στο ΕΡΓΑΝΗ:',
        };
    }

    public function erganiLine(): ?string
    {
        $l = $this->leave;
        $env = $l->ergani_env === 'production' ? '' : ' — ΔΟΚΙΜΑΣΤΙΚΟ περιβάλλον, χωρίς νομική ισχύ';

        return match ($l->ergani_status) {
            'submitted' => 'Δηλώθηκε, πρωτ. '.$l->ergani_protocol.' ('.$l->ergani_submitted_at?->format('d/m/Y H:i').')'.$env,
            'cancelled' => 'Ανακλήθηκε η δήλωση πρωτ. '.$l->ergani_protocol.$env,
            'failed', 'cancel_failed', 'unknown' => $l->ergani_error,
            default => null,
        };
    }

    public function content(): Content
    {
        return new Content(
            view: 'mail.hr.leave-accountant',
            text: 'mail.hr.leave-accountant_text',
            with: [
                'leave' => $this->leave,
                'event' => $this->event,
                'companyName' => $this->fromName,
                'intro' => $this->intro(),
                'erganiLine' => $this->erganiLine(),
            ],
        );
    }

    /** The official ΕΡΓΑΝΗ PDF, for a PRODUCTION declaration only (never breaks the send). */
    public function attachments(): array
    {
        return app(ErganiPdf::class)->attachmentFor($this->leave);
    }
}
