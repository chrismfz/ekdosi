<?php

namespace App\Jobs;

use App\Mail\TicketReplyMail;
use App\Models\Scopes\CompanyScope;
use App\Models\TicketMessage;
use App\Services\Support\Inbound\InboundTicketRouter;
use App\Services\TenantMailerFactory;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Emails an operator's public reply to the customer (Πυλώνας E, Phase 3b-ii),
 * FROM the department mailbox so the reply threads back to the poller. Stores our
 * outbound Message-ID on the ticket message (so the customer's References point at
 * it → {@see InboundTicketRouter} threads the reply),
 * and sets In-Reply-To/References to the customer message being answered.
 *
 * Only operator, public messages are mailed (never an internal note or a customer/
 * system message). Skips silently (logs, no throw) when there is no recipient or no
 * From address — a "nothing to send" non-error.
 */
class SendTicketReplyEmail implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 3;

    public function __construct(public int $ticketMessageId) {}

    /** @return array<int, int> */
    public function backoff(): array
    {
        return [30, 120, 300];
    }

    public function handle(TenantMailerFactory $mailerFactory): void
    {
        $message = TicketMessage::query()
            ->withoutGlobalScope(CompanyScope::class)
            ->with(['ticket.customer', 'ticket.department', 'ticket.company'])
            ->find($this->ticketMessageId);

        if ($message === null || $message->is_internal_note || $message->author_role !== TicketMessage::ROLE_OPERATOR) {
            return; // only an operator public reply is emailed to the customer
        }

        $ticket = $message->ticket;
        $company = $ticket?->company;
        if ($ticket === null || $company === null) {
            return;
        }

        $recipient = trim((string) ($ticket->customer?->email ?: $ticket->requester_email ?: ''));
        if ($recipient === '') {
            Log::info('SendTicketReplyEmail: no recipient', ['ticket_id' => $ticket->id]);

            return;
        }

        $department = $ticket->department;
        $fromAddress = trim((string) ($department?->email ?: $company->mail_from_address ?: config('mail.from.address')));
        if ($fromAddress === '') {
            Log::warning('SendTicketReplyEmail: no From address', ['ticket_id' => $ticket->id]);

            return;
        }
        $fromName = (string) ($department?->name ?: $company->mail_from_name ?: $company->name ?: config('mail.from.name'));

        // Our outbound Message-ID (bare, no brackets) — stored so the customer's
        // reply References it. Reuse the stored one on a retry (don't re-generate).
        $messageId = (string) $message->email_message_id;
        if ($messageId === '') {
            $domain = str_contains($fromAddress, '@') ? (string) substr(strrchr($fromAddress, '@'), 1) : 'ekdosi';
            $messageId = 'tk-'.$ticket->reference.'-'.$message->id.'-'.Str::lower(Str::random(8)).'@'.$domain;
            $message->forceFill(['email_message_id' => $messageId])->save();
        }

        // In-Reply-To = the customer message being answered (their most recent
        // inbound message that carries a Message-ID). Portal-only tickets have none
        // → a fresh thread (still threadable via our Message-ID + the subject token).
        $inReplyTo = TicketMessage::query()
            ->withoutGlobalScope(CompanyScope::class)
            ->where('ticket_id', $ticket->id)
            ->where('id', '<', $message->id)
            ->where('author_role', TicketMessage::ROLE_CUSTOMER)
            ->whereNotNull('email_message_id')
            ->orderByDesc('id')
            ->value('email_message_id');
        $inReplyTo = $inReplyTo !== null ? (string) $inReplyTo : null;

        $mailerFactory->for($company)->to($recipient)->send(new TicketReplyMail(
            ticket: $ticket,
            body: (string) $message->body,
            fromAddress: $fromAddress,
            fromName: $fromName,
            messageId: $messageId,
            inReplyTo: $inReplyTo,
            references: $inReplyTo !== null ? [$inReplyTo] : [],
        ));
    }
}
