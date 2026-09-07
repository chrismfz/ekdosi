<?php

namespace App\Jobs;

use App\Mail\TicketFeedbackMail;
use App\Models\Scopes\CompanyScope;
use App\Models\Ticket;
use App\Services\TenantMailerFactory;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\URL;

/**
 * Emails the customer a SIGNED «rate the service» link after a ticket closes
 * (Πυλώνας E, Phase 4 follow-up) — for customers who never revisit the portal.
 * Only when the ticket is genuinely ratable (closed, feedback-enabled department,
 * not merged) and not yet rated, with a reachable recipient. Skips silently
 * otherwise; a mail failure never affects the close.
 */
class SendTicketFeedbackInvite implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 3;

    public function __construct(public int $ticketId) {}

    /** @return array<int, int> */
    public function backoff(): array
    {
        return [30, 120, 300];
    }

    public function handle(TenantMailerFactory $mailerFactory): void
    {
        $ticket = Ticket::query()
            ->withoutGlobalScope(CompanyScope::class)
            ->with(['department', 'company', 'customer'])
            ->find($this->ticketId);

        $company = $ticket?->company;
        if ($ticket === null || $company === null || ! $company->hasSupport()) {
            return;
        }

        // Re-check at run time: a reopen (or a non-feedback department) makes it
        // non-ratable; an already-rated ticket must not be nagged.
        if (! $ticket->canBeRated() || $ticket->isRated()) {
            return;
        }

        $recipient = trim((string) ($ticket->requester_email ?: $ticket->customer?->email ?: ''));
        if ($recipient === '') {
            return;
        }

        $department = $ticket->department;
        $fromAddress = trim((string) ($department?->email ?: $company->mail_from_address ?: config('mail.from.address')));
        if ($fromAddress === '') {
            Log::warning('SendTicketFeedbackInvite: no From address', ['ticket_id' => $ticket->id]);

            return;
        }
        $fromName = (string) ($department?->name ?: $company->mail_from_name ?: $company->name ?: config('mail.from.name'));

        $url = URL::signedRoute('support.feedback.show', ['ticket' => $ticket->id]);

        $mailerFactory->for($company)->to($recipient)->send(new TicketFeedbackMail(
            ticket: $ticket,
            url: $url,
            fromAddress: $fromAddress,
            fromName: $fromName,
        ));
    }
}
