<?php

namespace App\Services\Support;

use App\Filament\Resources\Tickets\TicketResource;
use App\Models\Company;
use App\Models\Ticket;
use App\Models\TicketMessage;
use App\Models\User;
use App\Support\Hr\ErganiStaff;
use Filament\Notifications\Actions\Action;
use Filament\Notifications\Notification;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

/**
 * The operator «bell» for a ticket (Πυλώνας E, Phase 4). When a customer posts a
 * public message (a new ticket or a reply) the handling operators get a durable
 * Filament database notification so an inbound request is never silently unseen —
 * the same pattern as the WHMCS immediate-invoice bell.
 *
 * Recipients = the department's agents (or ALL tenant users when the department
 * has none assigned — «everyone handles it», WHMCS parity) ∪ the current assignee
 * ∪ the ticket's operator watchers. Everything here is best-effort: a notification
 * failure must never break message posting.
 */
class TicketNotifier
{
    /**
     * The operators to notify about activity on a ticket, deduped by id.
     *
     * @return Collection<int, User>
     */
    public function operatorRecipients(Ticket $ticket): Collection
    {
        $company = $ticket->company;
        if ($company === null) {
            return collect();
        }

        $department = $ticket->department;
        $agents = $department !== null ? $department->agents()->get() : collect();

        // No agents on the department → the whole tenant handles it.
        $base = $agents->isNotEmpty() ? $agents : ErganiStaff::staffRecipients($company);

        if ($ticket->assignee !== null) {
            $base = $base->push($ticket->assignee);
        }

        return $base->concat($ticket->operatorWatcherUsers())
            ->unique('id')
            ->values();
    }

    /**
     * Ring the operators' bell for a customer's public message. Silently skips
     * internal notes, operator/system messages, and tenants with support off.
     */
    public function notifyNewCustomerMessage(TicketMessage $message): void
    {
        if ($message->is_internal_note || $message->author_role !== TicketMessage::ROLE_CUSTOMER) {
            return;
        }

        try {
            $ticket = $message->ticket;
            $company = $ticket?->company;
            if ($ticket === null || $company === null || ! $company->hasSupport()) {
                return;
            }

            $recipients = $this->operatorRecipients($ticket);
            if ($recipients->isEmpty()) {
                return;
            }

            Notification::make()
                ->title('Νέο μήνυμα σε αίτημα υποστήριξης')
                ->body("[{$ticket->reference}] {$ticket->requesterLabel()} — {$ticket->subject}")
                ->icon('heroicon-o-lifebuoy')
                ->color('info')
                ->actions($this->viewActions($ticket, $company))
                ->sendToDatabase($recipients);
        } catch (\Throwable $e) {
            Log::warning('Ticket bell notification failed (message posting unaffected).', [
                'ticket_message_id' => $message->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * A «View» link action, but only when the panel URL can be built (route
     * generation needs the panel/tenant context, absent in some CLI paths). An
     * un-buildable URL simply yields a linkless bell.
     *
     * @return array<int, Action>
     */
    private function viewActions(Ticket $ticket, Company $company): array
    {
        try {
            $url = TicketResource::getUrl('view', ['record' => $ticket, 'tenant' => $company]);

            return [Action::make('view')->label('Άνοιγμα')->url($url)];
        } catch (\Throwable) {
            return [];
        }
    }
}
