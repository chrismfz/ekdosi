<?php

namespace App\Actions\Support;

use App\Enums\TicketStatus;
use App\Models\Ticket;
use App\Models\TicketMessage;
use Illuminate\Support\Facades\DB;

/**
 * Append a message to a ticket and advance its status per the state machine
 * (Πυλώνας E). The single choke-point both UIs and the mail poller call, so the
 * «who posted → what status» rule lives in exactly one place.
 *
 *   - A PUBLIC message moves the ticket to the poster's side
 *     ({@see TicketStatus::afterPublicMessageFrom}) and reopens a closed ticket;
 *     it stamps `last_reply_at` / `last_reply_role`.
 *   - An INTERNAL note changes nothing (not last_reply either) — it's operator
 *     scratch, invisible to the customer.
 *   - `$advanceStatus = false` keeps the current status (used for the FIRST
 *     message of a freshly-opened ticket, which stays «Ανοιχτό»).
 */
class PostTicketMessage
{
    /**
     * @param  array{author_role:string, author_id?:int|null, body:string, body_original?:string|null, is_internal_note?:bool, via?:string, email_message_id?:string|null}  $data
     */
    public function handle(Ticket $ticket, array $data, bool $advanceStatus = true): TicketMessage
    {
        return DB::transaction(function () use ($ticket, $data, $advanceStatus) {
            $isNote = (bool) ($data['is_internal_note'] ?? false);
            $role = $data['author_role'];

            $message = $ticket->messages()->create([
                'company_id' => $ticket->company_id,
                'author_role' => $role,
                'author_id' => $data['author_id'] ?? null,
                'body' => $data['body'],
                'body_original' => $data['body_original'] ?? null,
                'is_internal_note' => $isNote,
                'via' => $data['via'] ?? TicketMessage::VIA_OPERATOR,
                'email_message_id' => $data['email_message_id'] ?? null,
            ]);

            if (! $isNote) {
                if ($advanceStatus) {
                    $ticket->status = TicketStatus::afterPublicMessageFrom($role);
                    if ($ticket->status !== TicketStatus::Closed) {
                        $ticket->closed_at = null; // a public reply reopens a closed ticket
                    }
                }
                $ticket->last_reply_at = now();
                $ticket->last_reply_role = $role === TicketMessage::ROLE_OPERATOR ? 'operator' : 'customer';
                $ticket->save();
            }

            return $message;
        });
    }
}
