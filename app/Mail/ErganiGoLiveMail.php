<?php

namespace App\Mail;

use App\Models\Company;
use App\Services\Ergani\OvertimeService;
use App\Services\Ergani\WorkCardService;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * «Από σήμερα δηλώνει το ekdosi» → the accountant, sent by the «Πέρασμα σε
 * Παραγωγή» wizard: which declarations ekdosi now makes itself, so they STOP
 * declaring them by hand (a second declaration can't be undone). Greek only.
 */
class ErganiGoLiveMail extends Mailable
{
    /**
     * @param  list<string>  $trialOnly  future items declared only in the trial
     * @param  list<string>  $undeclared  future approved items not declared by ekdosi
     */
    public function __construct(
        public Company $company,
        public string $fromAddress,
        public string $fromName,
        public array $trialOnly = [],
        public array $undeclared = [],
    ) {}

    public function envelope(): Envelope
    {
        $from = new Address($this->fromAddress, $this->fromName);

        return new Envelope(from: $from, replyTo: [$from],
            subject: 'ΕΡΓΑΝΗ: από '.now()->format('d/m/Y').' οι δηλώσεις γίνονται αυτόματα από το ekdosi — '.$this->company->name);
    }

    /** @return list<string> */
    public function declared(): array
    {
        return array_keys(array_filter([
            'οι εγκεκριμένες άδειες (και οι ανακλήσεις τους)' => (bool) $this->company->ergani_submit_leaves,
            'οι υπερωρίες' => OvertimeService::enabledFor($this->company),
            'η ψηφιακή κάρτα εργασίας' => WorkCardService::enabledFor($this->company),
        ]));
    }

    public function content(): Content
    {
        return new Content(
            view: 'mail.hr.ergani-golive',
            text: 'mail.hr.ergani-golive_text',
            with: ['company' => $this->company, 'items' => $this->declared(), 'companyName' => $this->fromName,
                'pending' => array_merge($this->trialOnly, $this->undeclared)],
        );
    }
}
