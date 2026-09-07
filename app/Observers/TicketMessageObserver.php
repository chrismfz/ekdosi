<?php

namespace App\Observers;

use App\Models\TicketMessage;
use App\Models\TicketWatcher;
use App\Services\Support\TicketNotifier;
use Illuminate\Contracts\Events\ShouldHandleEventsAfterCommit;

/**
 * Reacts to every new ticket message (Πυλώνας E, Phase 4), AFTER the surrounding
 * transaction commits (OpenTicket/PostTicketMessage both wrap the insert), so the
 * ticket + message are durable before we notify or write a watcher:
 *
 *   - an OPERATOR message → its author auto-watches the ticket (participant), so
 *     they keep getting the bell on the customer's future replies;
 *   - a CUSTOMER public message → rings the operators' bell (TicketNotifier).
 *
 * Both are best-effort side effects — the participant watch is a plain idempotent
 * upsert; the bell is wrapped best-effort inside the notifier.
 */
class TicketMessageObserver implements ShouldHandleEventsAfterCommit
{
    public function __construct(private readonly TicketNotifier $notifier) {}

    public function created(TicketMessage $message): void
    {
        if ($message->author_role === TicketMessage::ROLE_OPERATOR) {
            // Auto-watch only on a PUBLIC reply — jotting a private internal note
            // must not subscribe the operator to the customer bell.
            if ($message->author_id !== null && ! $message->is_internal_note) {
                $this->autoWatchAuthor($message);
            }

            return; // an operator's own message never rings their own bell
        }

        $this->notifier->notifyNewCustomerMessage($message);
    }

    /**
     * The replying operator becomes a participant watcher (idempotent). The author
     * is resolved WITHIN the ticket's tenant — the same invariant the «Προσθήκη
     * watcher» action enforces, so no watcher-creation path attaches a foreign user.
     */
    private function autoWatchAuthor(TicketMessage $message): void
    {
        $ticket = $message->ticket;
        if ($ticket === null) {
            return;
        }

        $author = $ticket->company?->users()->whereKey($message->author_id)->first();
        if ($author === null) {
            return;
        }

        $ticket->watch($author, TicketWatcher::SOURCE_PARTICIPANT);
    }
}
