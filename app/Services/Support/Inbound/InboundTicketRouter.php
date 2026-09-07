<?php

namespace App\Services\Support\Inbound;

use App\Actions\Support\OpenTicket;
use App\Actions\Support\PostTicketMessage;
use App\Models\Customer;
use App\Models\Scopes\CompanyScope;
use App\Models\Ticket;
use App\Models\TicketBlockedSender;
use App\Models\TicketDepartment;
use App\Models\TicketMessage;
use App\Models\TicketWatcher;
use App\Support\TicketReference;
use EmailReplyParser\EmailReplyParser;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Log;

/**
 * Routes a parsed inbound email into a ticket (Πυλώνας E, Phase 3a): append to a
 * matched ticket, or open a new one — through the SAME OpenTicket/PostTicketMessage
 * choke-point the operator and portal use, so the state machine stays in one place.
 *
 * The order that matters:
 *   0. IDEMPOTENCY — if we already stored a message with this Message-ID, this is a
 *      redelivery (poller retry / overlapping poll): return that ticket, do nothing.
 *   1. Match the sender → a Customer of the department's company (email /
 *      secondary_email). A `clients_only` department REJECTS an unknown sender.
 *   2. Match an existing ticket: (a) References/In-Reply-To → a Message-ID we
 *      stored; (b) a `[TK-…]` token in the subject. A candidate is threaded ONLY if
 *      the SENDER OWNS it (its customer, or its guest requester_email) — a CC'd
 *      stranger with the token/References must NOT inject into someone's thread.
 *      Otherwise a NEW ticket (GUEST if the sender is unknown).
 *   3. Clean the body (strip quoted history/signature) — the raw stays in body_original.
 *
 * All matching is company-scoped (explicit company_id off-panel), so no
 * cross-company threading. Message-IDs are normalised (angle brackets stripped) on
 * both store and compare, so threading is bracket-agnostic. Returns the ticket, or
 * null if rejected.
 */
class InboundTicketRouter
{
    public function __construct(
        private readonly OpenTicket $openTicket,
        private readonly PostTicketMessage $postMessage,
    ) {}

    public function route(TicketDepartment $department, ParsedInboundEmail $email): ?Ticket
    {
        // No usable sender → can't bind or thread; drop (junk / system mail) rather
        // than open an unanswerable guest ticket with a blank requester.
        if (trim($email->fromEmail) === '') {
            return null;
        }

        $companyId = (int) $department->company_id;

        // Blocklist (spam/block-sender): drop before any customer/thread match, so a
        // blocked sender is fully silenced — no new ticket, no reopen, and no reply
        // appended to an existing thread. A DOMAIN block matches that exact domain
        // (not subdomains) and is intentionally blunt — it catches every address on
        // it; prefer a full-email block to spare legitimate colleagues. (Softening to
        // «new/reopen only», and subdomain matching, are BACKLOG options.)
        if (TicketBlockedSender::isBlocked($companyId, $email->fromEmail)) {
            Log::info('InboundTicketRouter: blocked sender dropped', [
                'company_id' => $companyId,
                'department_id' => $department->id,
                'from' => $email->fromEmail,
            ]);

            return null;
        }

        $messageId = $this->normaliseId($email->messageId);

        // (0) Already processed this exact message? Return its ticket, don't duplicate.
        if ($messageId !== null) {
            $seen = TicketMessage::query()
                ->withoutGlobalScope(CompanyScope::class)
                ->where('company_id', $companyId)
                ->where('email_message_id', $messageId)
                ->first();
            if ($seen !== null) {
                return $seen->ticket;
            }
        }

        $customer = $this->matchCustomer($companyId, $email->fromEmail);

        // «Clients Only»: an unknown sender is rejected outright (no ticket, no leak).
        if ($department->clients_only && $customer === null) {
            return null;
        }

        // Thread onto a matched ticket ONLY if this sender owns it — else a stranger
        // holding the (non-secret) Message-ID or the token would inject into a thread.
        $existing = $this->matchTicket($companyId, $email);
        if ($existing !== null && ! $this->senderOwnsTicket($existing, $customer, $email->fromEmail)) {
            $existing = null;
        }

        $cleanBody = $this->cleanBody($email->body);

        if ($existing !== null) {
            // A reply from a watcher/CC (not the owner) is threaded, but ticket_messages
            // has no sender column — so prefix the real sender, else the developer's
            // words would read as the customer's own in the panel + portal.
            $body = $this->senderIsOwner($existing, $customer, $email->fromEmail)
                ? $cleanBody
                : '(από '.$email->fromEmail.")\n\n".$cleanBody;

            $this->postMessage->handle($existing, [
                'author_role' => TicketMessage::ROLE_CUSTOMER,
                'author_id' => $customer?->id,
                'is_internal_note' => false,
                'via' => TicketMessage::VIA_EMAIL,
                'body' => $body,
                'body_original' => $email->body,
                'email_message_id' => $messageId,
            ]);
            $this->captureCcWatchers($existing, $email, $department, $customer);

            return $existing;
        }

        $ticket = $this->openTicket->handle([
            'company_id' => $companyId,
            'customer_id' => $customer?->id,
            'ticket_department_id' => $department->id,
            'requester_email' => $email->fromEmail,
            'requester_name' => $email->fromName,
            'subject' => $this->cleanSubject($email->subject),
            'priority' => 'normal',
            'opened_via' => 'email',
            'author_role' => TicketMessage::ROLE_CUSTOMER,
            'author_id' => $customer?->id,
            'via' => TicketMessage::VIA_EMAIL,
            'body' => $cleanBody,
            'body_original' => $email->body,
            'email_message_id' => $messageId,
        ]);
        $this->captureCcWatchers($ticket, $email, $department, $customer);

        return $ticket;
    }

    /**
     * Record the email's OTHER recipients (To + Cc) as email watchers/CC on the
     * ticket (Πυλώνας E, Phase 4) — the parties the sender looped in, so our
     * replies copy them too. Idempotent (firstOrCreate), so a re-capture on a later
     * reply never duplicates.
     *
     * SAFETY: only when the sender is a KNOWN customer — otherwise an anonymous
     * sender could subscribe arbitrary third parties to our outbound mail (an
     * open-relay/harassment vector). Excludes the sender, the department mailbox
     * (display AND polled address), the ticket owner (primary/secondary email +
     * requester), our own From addresses, and anything blocked or not a valid email.
     */
    private function captureCcWatchers(Ticket $ticket, ParsedInboundEmail $email, TicketDepartment $department, ?Customer $customer): void
    {
        // Only the ticket's OWNER may add recipients — never an unknown sender, and
        // never a watcher-customer replying (they must not subscribe third parties to
        // someone else's ticket).
        if ($customer === null || (int) $ticket->customer_id !== (int) $customer->id) {
            return;
        }

        $companyId = (int) $ticket->company_id;
        $exclude = array_filter(array_map(
            fn (?string $v): string => mb_strtolower(trim((string) $v)),
            [
                $email->fromEmail,
                $department->email,
                $department->imap_username, // the actually-polled mailbox → no reply loop
                $customer->email,
                $customer->secondary_email,
                $ticket->requester_email,
                $ticket->company?->mail_from_address,
                (string) config('mail.from.address'),
            ],
        ));

        foreach (array_merge($email->to, $email->cc) as $address) {
            $address = mb_strtolower(trim($address));
            if ($address === '' || in_array($address, $exclude, true)
                || filter_var($address, FILTER_VALIDATE_EMAIL) === false
                || TicketBlockedSender::isBlocked($companyId, $address)) {
                continue;
            }
            $ticket->addEmailWatcher($address, TicketWatcher::SOURCE_CC);
        }
    }

    /** The sender is the ticket's customer, or (for a guest ticket) its requester_email. */
    /** The sender IS the ticket's owner — its customer, or (for a guest) its requester_email. */
    private function senderIsOwner(Ticket $ticket, ?Customer $customer, string $fromEmail): bool
    {
        if ($customer !== null && (int) $ticket->customer_id === (int) $customer->id) {
            return true;
        }

        $from = mb_strtolower(trim($fromEmail));

        return $from !== '' && mb_strtolower(trim((string) $ticket->requester_email)) === $from;
    }

    /**
     * May this sender thread onto the ticket? The owner, OR a watcher/CC of THIS
     * ticket (a legitimate participant added by the customer's CC or an operator),
     * so their reply threads here rather than opening a new ticket. The token/
     * References alone never suffice — the sender must be the owner or on the watcher
     * list. NOTE: like the whole inbound path, this trusts the From header (no SPF/
     * DKIM); broadening the trusted set from the owner to the customer-controllable
     * watcher list is a conscious tradeoff (see docs/BACKLOG.md).
     */
    private function senderOwnsTicket(Ticket $ticket, ?Customer $customer, string $fromEmail): bool
    {
        if ($this->senderIsOwner($ticket, $customer, $fromEmail)) {
            return true;
        }

        $from = mb_strtolower(trim($fromEmail));

        return $from !== '' && in_array($from, $ticket->watcherEmailAddresses(), true);
    }

    private function matchCustomer(int $companyId, string $fromEmail): ?Customer
    {
        $needle = mb_strtolower(trim($fromEmail));
        if ($needle === '') {
            return null;
        }

        return Customer::query()
            ->withoutGlobalScope(CompanyScope::class)
            ->where('company_id', $companyId)
            ->where(fn (Builder $q): Builder => $q
                ->whereRaw('LOWER(email) = ?', [$needle])
                ->orWhereRaw('LOWER(secondary_email) = ?', [$needle]))
            ->first();
    }

    private function matchTicket(int $companyId, ParsedInboundEmail $email): ?Ticket
    {
        // (a) In-Reply-To / References → a Message-ID we stored on a prior message.
        $refs = array_values(array_filter(array_map(
            fn (string $r): ?string => $this->normaliseId($r),
            $email->references,
        )));
        if ($refs !== []) {
            $byReference = Ticket::query()
                ->withoutGlobalScope(CompanyScope::class)
                ->where('company_id', $companyId)
                ->whereHas('messages', fn (Builder $q) => $q->whereIn('email_message_id', $refs))
                ->first();
            if ($byReference !== null) {
                return $byReference;
            }
        }

        // (b) a [TK-…] token in the subject.
        $reference = $this->extractReference($email->subject);
        if ($reference !== null) {
            return Ticket::query()
                ->withoutGlobalScope(CompanyScope::class)
                ->where('company_id', $companyId)
                ->where('reference', $reference)
                ->first();
        }

        return null;
    }

    private function extractReference(string $subject): ?string
    {
        if (preg_match('/('.TicketReference::pattern().')/', $subject, $m) === 1) {
            return $m[1];
        }

        return null;
    }

    /** A new-ticket subject with the [TK-…] token and STACKED Re:/Fwd: noise stripped. */
    private function cleanSubject(string $subject): string
    {
        $subject = (string) preg_replace('/\[?'.TicketReference::pattern().'\]?/', '', $subject);
        $subject = trim($subject);

        $prefix = '/^\s*(re|fwd|fw|απ|σχετ)\s*:\s*/iu';
        while (preg_match($prefix, $subject) === 1) {
            $subject = (string) preg_replace($prefix, '', $subject);
        }
        $subject = trim($subject);

        return $subject !== '' ? mb_substr($subject, 0, 191) : '(χωρίς θέμα)';
    }

    /** Strip quoted history/signature; fall back to the raw body if it over-strips. */
    private function cleanBody(string $body): string
    {
        $clean = trim(EmailReplyParser::parseReply($body));

        return $clean !== '' ? $clean : trim($body);
    }

    /** Normalise a Message-ID for storage/comparison: trim + strip angle brackets. */
    private function normaliseId(?string $id): ?string
    {
        if ($id === null) {
            return null;
        }

        $id = trim(trim($id), '<>');

        return $id === '' ? null : $id;
    }
}
