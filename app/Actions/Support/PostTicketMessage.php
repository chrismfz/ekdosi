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
                'via' => $data['via'] ?? TicketMessage::defaultViaFor($role),
                'email_message_id' => $data['email_message_id'] ?? null,
            ]);

            // Only a public reply from the customer or an operator "counts": it
            // advances status and stamps last_reply. Internal notes AND system
            // (autoresponder) messages never move the ticket or the last-reply
            // marker — a system message must not read as if the customer replied.
            $countsAsReply = ! $isNote
                && in_array($role, [TicketMessage::ROLE_CUSTOMER, TicketMessage::ROLE_OPERATOR], true);

            if ($countsAsReply) {
                if ($advanceStatus) {
                    // afterPublicMessageFrom() only ever returns Answered/CustomerReply,
                    // so a public reply always reopens a closed ticket.
                    $ticket->status = TicketStatus::afterPublicMessageFrom($role);
                    $ticket->closed_at = null;
                }
                $ticket->last_reply_at = now();
                $ticket->last_reply_role = $role === TicketMessage::ROLE_OPERATOR ? 'operator' : 'customer';
                $ticket->save();
            }

            return $message;
        });
    }
}
