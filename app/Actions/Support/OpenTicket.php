<?php

namespace App\Actions\Support;

use App\Enums\TicketPriority;
use App\Enums\TicketStatus;
use App\Models\Ticket;
use App\Models\TicketMessage;
use App\Support\TicketReference;
use Illuminate\Support\Facades\DB;

/**
 * Open a new ticket with its first message (Πυλώνας E). Allocates the
 * `TK-YYYY-MM-DD-xxxxxx` reference, creates the ticket at «Ανοιχτό», and appends
 * the opening message via {@see PostTicketMessage} WITHOUT advancing status (a new
 * ticket stays Open whoever opened it) while still stamping last_reply. Used by
 * the operator UI, the portal (Phase 2) and the mail poller (Phase 3).
 */
class OpenTicket
{
    public function __construct(private readonly PostTicketMessage $postMessage) {}

    /**
     * @param  array{company_id:int, ticket_department_id?:int|null, customer_id?:int|null, requester_email?:string|null, requester_name?:string|null, subject:string, priority?:string, opened_via?:string, body:string, body_original?:string|null, author_role:string, author_id?:int|null, via?:string, email_message_id?:string|null}  $data
     */
    public function handle(array $data): Ticket
    {
        return DB::transaction(function () use ($data) {
            $companyId = $data['company_id'];

            $ticket = Ticket::create([
                'company_id' => $companyId,
                'reference' => TicketReference::generate($companyId),
                'ticket_department_id' => $data['ticket_department_id'] ?? null,
                'customer_id' => $data['customer_id'] ?? null,
                'requester_email' => $data['requester_email'] ?? null,
                'requester_name' => $data['requester_name'] ?? null,
                'subject' => $data['subject'],
                'status' => TicketStatus::Open,
                'priority' => $data['priority'] ?? TicketPriority::Normal->value,
                'opened_via' => $data['opened_via'] ?? 'operator',
            ]);

            $this->postMessage->handle($ticket, [
                'author_role' => $data['author_role'],
                'author_id' => $data['author_id'] ?? null,
                'body' => $data['body'],
                'body_original' => $data['body_original'] ?? null,
                'is_internal_note' => false,
                'via' => $data['via'] ?? TicketMessage::defaultViaFor($data['author_role']),
                'email_message_id' => $data['email_message_id'] ?? null,
            ], advanceStatus: false);

            return $ticket->refresh();
        });
    }
}
