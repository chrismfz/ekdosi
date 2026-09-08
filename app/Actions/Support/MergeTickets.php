<?php

namespace App\Actions\Support;

use App\Enums\TicketStatus;
use App\Models\Attachment;
use App\Models\Scopes\CompanyScope;
use App\Models\Ticket;
use App\Models\TicketMessage;
use Illuminate\Support\Facades\DB;

/**
 * Merge a duplicate ticket into a surviving one (Πυλώνας E, Phase 4). The source's
 * messages, watchers, tags and ticket-level attachments move to the target; the
 * source is closed with `merged_into_id` set (terminal — no reopen). The target
 * keeps its reference/subject/priority/assignee/status/owner; only its last-reply
 * marker is recomputed from the combined thread.
 *
 * SAFETY: only same-owner tickets may merge ({@see Ticket::canMergeInto}) — a
 * cross-owner merge would leak one party's thread into the other's portal. The
 * caller (UI) pre-filters targets; this re-checks and throws on violation.
 */
class MergeTickets
{
    public function handle(Ticket $source, Ticket $target): Ticket
    {
        if (! $source->canMergeInto($target)) {
            throw new \InvalidArgumentException('Τα αιτήματα δεν μπορούν να συγχωνευθούν (διαφορετικός πελάτης ή ήδη συγχωνευμένο).');
        }

        return DB::transaction(function () use ($source, $target): Ticket {
            // 1. Re-parent every source message to the target (bulk — no observer
            //    side effects; chronology stays via each message's created_at/id).
            TicketMessage::query()
                ->withoutGlobalScope(CompanyScope::class)
                ->where('ticket_id', $source->id)
                ->update(['ticket_id' => $target->id]);

            // 2. Ticket-level attachments move; message attachments already followed
            //    their (now re-parented) messages. Drop CompanyScope like the message
            //    re-parent above, so this never silently no-ops under a mismatched
            //    ambient tenant (source/target share a company — canMergeInto checks it).
            Attachment::query()
                ->withoutGlobalScope(CompanyScope::class)
                ->where('company_id', $source->company_id)
                ->where('attachable_type', $source->getMorphClass())
                ->where('attachable_id', $source->id)
                ->update(['attachable_id' => $target->id]);

            // 3. Union tags + 4. union watchers (idempotent), then drop the source's.
            $target->tags()->syncWithoutDetaching($source->tags->pluck('id')->all());

            foreach ($source->watchers()->get() as $watcher) {
                $match = $watcher->user_id !== null
                    ? ['user_id' => $watcher->user_id]
                    : ['email' => $watcher->email];
                $target->watchers()->firstOrCreate($match, [
                    'company_id' => $target->company_id,
                    'source' => $watcher->source,
                ]);
            }
            $source->watchers()->delete();

            // 5. Recompute the target's last-reply from the combined thread (system
            //    notes never count as a reply).
            $lastReply = $target->messages()
                ->reorder() // drop the relation's default id-ASC order, else first() takes the oldest
                ->where('is_internal_note', false)
                ->whereIn('author_role', [TicketMessage::ROLE_CUSTOMER, TicketMessage::ROLE_OPERATOR])
                ->orderByDesc('created_at')->orderByDesc('id')
                ->first();
            if ($lastReply !== null) {
                $target->last_reply_at = $lastReply->created_at;
                $target->last_reply_role = $lastReply->author_role === TicketMessage::ROLE_OPERATOR ? 'operator' : 'customer';
            }
            $target->save();

            // 6. Audit notes on both sides (internal — operator housekeeping, never
            //    shown to the customer in the portal).
            $this->systemNote($target, "Ενσωματώθηκε το αίτημα {$source->reference}.");
            $this->systemNote($source, "Συγχωνεύθηκε στο αίτημα {$target->reference}.");

            // 7. Close the source terminally, pointing at the survivor.
            $source->forceFill([
                'status' => TicketStatus::Closed->value,
                'closed_at' => now(),
                'merged_into_id' => $target->id,
            ])->save();

            return $target->refresh();
        });
    }

    private function systemNote(Ticket $ticket, string $body): void
    {
        $ticket->messages()->create([
            'company_id' => $ticket->company_id,
            'author_role' => TicketMessage::ROLE_SYSTEM,
            'body' => $body,
            'is_internal_note' => true,
            'via' => TicketMessage::VIA_SYSTEM,
        ]);
    }
}
