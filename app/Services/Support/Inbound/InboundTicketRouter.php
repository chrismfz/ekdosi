<?php

namespace App\Services\Support\Inbound;

use App\Actions\Support\OpenTicket;
use App\Actions\Support\PostTicketMessage;
use App\Models\Customer;
use App\Models\Scopes\CompanyScope;
use App\Models\Ticket;
use App\Models\TicketDepartment;
use App\Models\TicketMessage;
use EmailReplyParser\EmailReplyParser;
use Illuminate\Database\Eloquent\Builder;

/**
 * Routes a parsed inbound email into a ticket (Πυλώνας E, Phase 3a): append to a
 * matched ticket, or open a new one — through the SAME OpenTicket/PostTicketMessage
 * choke-point the operator and portal use, so the state machine stays in one place.
 *
 * The order that matters:
 *   1. Match the sender → a Customer of the department's company (email /
 *      secondary_email). A `clients_only` department REJECTS an unknown sender.
 *   2. Match an existing ticket: (a) References/In-Reply-To → a Message-ID we
 *      stored on a prior message; (b) a `[TK-YYYY-MM-DD-xxxxxx]` token in the
 *      subject. Neither ⇒ a NEW ticket (GUEST if the sender is unknown).
 *   3. Clean the body (strip quoted history/signature) for a reply.
 *
 * Everything is off-panel, so it drops CompanyScope and filters by the
 * department's company_id explicitly. Returns the ticket, or null if rejected.
 */
class InboundTicketRouter
{
    public function __construct(
        private readonly OpenTicket $openTicket,
        private readonly PostTicketMessage $postMessage,
    ) {}

    public function route(TicketDepartment $department, ParsedInboundEmail $email): ?Ticket
    {
        $companyId = (int) $department->company_id;
        $customer = $this->matchCustomer($companyId, $email->fromEmail);

        // «Clients Only»: an unknown sender is rejected outright (no ticket, no leak).
        if ($department->clients_only && $customer === null) {
            return null;
        }

        $existing = $this->matchTicket($companyId, $email);
        $cleanBody = $this->cleanBody($email->body);

        if ($existing !== null) {
            $this->postMessage->handle($existing, [
                'author_role' => TicketMessage::ROLE_CUSTOMER,
                'author_id' => $customer?->id,
                'is_internal_note' => false,
                'via' => TicketMessage::VIA_EMAIL,
                'body' => $cleanBody,
                'body_original' => $email->body,
                'email_message_id' => $email->messageId,
            ]);

            return $existing;
        }

        return $this->openTicket->handle([
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
            'email_message_id' => $email->messageId,
        ]);
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
        if ($email->references !== []) {
            $byReference = Ticket::query()
                ->withoutGlobalScope(CompanyScope::class)
                ->where('company_id', $companyId)
                ->whereHas('messages', fn (Builder $q) => $q->whereIn('email_message_id', $email->references))
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
        if (preg_match('/(TK-\d{4}-\d{2}-\d{2}-[2-9A-HJKMNP-Z]{6})/', $subject, $m) === 1) {
            return $m[1];
        }

        return null;
    }

    /** A new-ticket subject with the [TK-…] token and Re:/Fwd: noise stripped. */
    private function cleanSubject(string $subject): string
    {
        $subject = (string) preg_replace('/\[?TK-\d{4}-\d{2}-\d{2}-[2-9A-HJKMNP-Z]{6}\]?/', '', $subject);
        $subject = (string) preg_replace('/^\s*(re|fwd|fw|απ|σχετ)\s*:\s*/iu', '', trim($subject));
        $subject = trim($subject);

        return $subject !== '' ? mb_substr($subject, 0, 191) : '(χωρίς θέμα)';
    }

    /** Strip quoted history/signature; fall back to the raw body if it over-strips. */
    private function cleanBody(string $body): string
    {
        $clean = trim(EmailReplyParser::parseReply($body));

        return $clean !== '' ? $clean : trim($body);
    }
}
