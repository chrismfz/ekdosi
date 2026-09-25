<?php

namespace App\Mail;

use App\Models\OvertimeDeclaration;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * «Υπερωρία» → the tenant's accountant (companies.leave_notify_email) for
 * payroll: declared by ekdosi (protocol), or NOT declared (they must know it
 * is missing). Operator-side, Greek only.
 */
class OvertimeAccountantMail extends Mailable
{
    public function __construct(
        public OvertimeDeclaration $overtime,
        public string $fromAddress,
        public string $fromName,
    ) {}

    public function envelope(): Envelope
    {
        $from = new Address($this->fromAddress, $this->fromName);

        return new Envelope(
            from: $from,
            replyTo: [$from],
            subject: sprintf('%s: %s — %s',
                $this->overtime->ergani_status === 'submitted' ? 'Υπερωρία' : 'Υπερωρία ΧΩΡΙΣ δήλωση ΕΡΓΑΝΗ',
                $this->overtime->employee?->full_name,
                $this->overtime->slotLabel()),
        );
    }

    public function intro(): string
    {
        $o = $this->overtime;
        $trial = $o->ergani_env !== 'production';

        return match ($o->ergani_status) {
            'submitted' => $trial
                ? 'Καταχωρήθηκε η παρακάτω υπερωρία (ΔΟΚΙΜΑΣΤΙΚΟ περιβάλλον ΕΡΓΑΝΗ — χωρίς νομική ισχύ· παρακαλούμε για τη δήλωσή της).'
                : 'Η παρακάτω υπερωρία δηλώθηκε ήδη αυτόματα στο ΕΡΓΑΝΗ — για τη μισθοδοσία, δεν χρειάζεται νέα δήλωση.',
            // TRIAL: ekdosi never declares for real → the accountant stays THE declarer.
            'unknown', 'failed' => $trial
                ? 'Καταχωρήθηκε η παρακάτω υπερωρία (το ekdosi δοκιμάζει ακόμη το ΕΡΓΑΝΗ σε ΔΟΚΙΜΑΣΤΙΚΟ περιβάλλον, χωρίς νομική ισχύ) — παρακαλούμε για τη δήλωσή της, πριν την έναρξη.'
                // PRODUCTION: informational ONLY — the company retries from ekdosi; a manual
                // declaration too would be a second binding, non-withdrawable WTOOv.
                : ($o->ergani_status === 'unknown'
                    ? 'Η αυτόματη δήλωση της παρακάτω υπερωρίας έχει ΑΓΝΩΣΤΟ αποτέλεσμα. Την ελέγχει η εταιρεία — ΜΗΝ τη δηλώσετε εσείς χωρίς να σας το ζητήσουμε.'
                    : 'Η παρακάτω υπερωρία ΔΕΝ δηλώθηκε αυτόματα στο ΕΡΓΑΝΗ. Η εταιρεία θα την ξαναδηλώσει από το ekdosi ή θα σας ενημερώσει — ΜΗΝ τη δηλώσετε εσείς χωρίς να σας το ζητήσουμε.'),
            default => 'Καταχωρήθηκε η παρακάτω υπερωρία — παρακαλούμε για τη δήλωσή της στο ΕΡΓΑΝΗ, πριν την έναρξη.',
        };
    }

    public function content(): Content
    {
        $o = $this->overtime;

        return new Content(
            view: 'mail.hr.overtime-accountant',
            text: 'mail.hr.overtime-accountant_text',
            with: [
                'o' => $o,
                'intro' => $this->intro(),
                'companyName' => $this->fromName,
                'erganiLine' => $o->ergani_status === 'submitted'
                    ? 'Δηλώθηκε, πρωτ. '.$o->ergani_protocol.' ('.$o->ergani_submitted_at?->format('d/m/Y H:i').')'
                        .($o->ergani_env === 'production' ? '' : ' — ΔΟΚΙΜΑΣΤΙΚΟ')
                    : $o->ergani_error,
            ],
        );
    }
}
