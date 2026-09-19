<?php

namespace App\Jobs;

use App\Mail\TicketReplyMail;
use App\Models\Scopes\CompanyScope;
use App\Models\TicketMessage;
use App\Services\Support\Inbound\InboundTicketRouter;
use App\Services\TenantMailerFactory;
use App\Support\CustomerLanguage;
use App\Support\TicketAttachments;
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
        if ($ticket === null || $company === null || ! $company->hasSupport()) {
            return; // pillar turned off for the tenant → don't email from an unmonitored box
        }

        // Prefer the address that actually wrote in (requester_email) over the
        // customer record's primary — the customer may have contacted from a
        // secondary/other address and monitors THAT one.
        $recipient = trim((string) ($ticket->requester_email ?: $ticket->customer?->email ?: ''));
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

        // References = the full chain of prior message-ids (chronological, RFC 5322);
        // In-Reply-To = the immediate parent (the last one). Portal-only tickets have
        // no ids → a fresh thread (still threadable via our Message-ID + subject token).
        $chain = TicketMessage::query()
            ->withoutGlobalScope(CompanyScope::class)
            ->where('ticket_id', $ticket->id)
            ->where('id', '<', $message->id)
            ->whereNotNull('email_message_id')
            ->orderBy('id')
            ->pluck('email_message_id')
            ->map(fn ($id): string => (string) $id)
            ->all();
        $inReplyTo = $chain !== [] ? $chain[array_key_last($chain)] : null;

        // Copy the ticket's external watchers (Phase 4). CC-sourced ones (openly on
        // the customer's original thread) go VISIBLE Cc; manual ones stay hidden Bcc.
        // Both minus the recipient/From, and any malformed address dropped — one bad
        // watcher row must never abort the reply to the customer.
        $skip = [mb_strtolower($recipient), mb_strtolower($fromAddress)];
        $keep = fn (string $address): bool => ! in_array($address, $skip, true)
            && filter_var($address, FILTER_VALIDATE_EMAIL) !== false;

        $split = $ticket->watcherEmailsForReply();
        $cc = array_values(array_filter($split['cc'], $keep));
        // Never Bcc an address already visible in Cc.
        $bcc = array_values(array_filter(
            array_diff($split['bcc'], $cc),
            $keep,
        ));

        // The operator reply's own attachments (PR B), read from the private disk at
        // send time. outboundPayload drops any file whose bytes are gone and, if the
        // set exceeds the per-email budget, returns [] (all-or-nothing — a giant that
        // bounces would deliver nothing). Whenever fewer files go out than are on the
        // message, log it (missing-on-disk OR over budget) so the drop isn't silent.
        $stored = $message->attachments;
        $attachmentFiles = TicketAttachments::outboundPayload($stored);
        if ($stored->isNotEmpty() && count($attachmentFiles) < $stored->count()) {
            Log::warning('SendTicketReplyEmail: reply sent without some attachments (missing on disk or over the per-email size budget)', [
                'ticket_id' => $ticket->id,
                'ticket_message_id' => $message->id,
                'stored' => $stored->count(),
                'attached' => count($attachmentFiles),
            ]);
        }

        // i18n: the reply follows the customer's language (else the tenant default).
        $locale = $ticket->customer
            ? CustomerLanguage::forCustomerMail($ticket->customer)
            : CustomerLanguage::forHost($company);

        $mailerFactory->for($company)->to($recipient)->send((new TicketReplyMail(
            ticket: $ticket,
            body: (string) $message->body,
            fromAddress: $fromAddress,
            fromName: $fromName,
            messageId: $messageId,
            inReplyTo: $inReplyTo,
            references: $chain,
            ccAddresses: $cc,
            bccAddresses: $bcc,
            attachmentFiles: $attachmentFiles,
        ))->locale($locale));
    }
}
