<?php

namespace App\Filament\Resources\Tickets\Pages;

use App\Actions\Support\OpenTicket;
use App\Enums\TicketPriority;
use App\Filament\Resources\Tickets\TicketResource;
use App\Models\TicketMessage;
use Filament\Facades\Filament;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

class CreateTicket extends CreateRecord
{
    protected static string $resource = TicketResource::class;

    /**
     * Route creation through OpenTicket: it allocates the reference and posts the
     * opening message (authored by the operator, opened_via=operator) atomically.
     */
    protected function handleRecordCreation(array $data): Model
    {
        $tenant = Filament::getTenant();
        if (! $tenant) {
            throw new \RuntimeException('Cannot open a ticket without a tenant context.');
        }

        return app(OpenTicket::class)->handle([
            'company_id' => $tenant->getKey(),
            'ticket_department_id' => $data['ticket_department_id'] ?? null,
            'customer_id' => $data['customer_id'] ?? null,
            'requester_email' => $data['requester_email'] ?? null,
            'requester_name' => $data['requester_name'] ?? null,
            'subject' => $data['subject'],
            'priority' => $data['priority'] ?? TicketPriority::Normal->value,
            'opened_via' => 'operator',
            'author_role' => TicketMessage::ROLE_OPERATOR,
            'author_id' => auth()->id(),
            'via' => TicketMessage::VIA_OPERATOR,
            'body' => $data['body'],
        ]);
    }

    protected function getRedirectUrl(): string
    {
        return static::getResource()::getUrl('view', ['record' => $this->getRecord()]);
    }
}
