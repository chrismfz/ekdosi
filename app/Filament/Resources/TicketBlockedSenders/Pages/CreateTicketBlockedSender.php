<?php

namespace App\Filament\Resources\TicketBlockedSenders\Pages;

use App\Filament\Resources\TicketBlockedSenders\TicketBlockedSenderResource;
use Filament\Resources\Pages\CreateRecord;

class CreateTicketBlockedSender extends CreateRecord
{
    protected static string $resource = TicketBlockedSenderResource::class;

    /** Stamp the acting operator (company_id is filled by Filament's tenancy). */
    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['created_by'] = auth()->id();

        return $data;
    }
}
