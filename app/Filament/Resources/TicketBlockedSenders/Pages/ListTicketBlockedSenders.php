<?php

namespace App\Filament\Resources\TicketBlockedSenders\Pages;

use App\Filament\BaseListRecords;
use App\Filament\Resources\TicketBlockedSenders\TicketBlockedSenderResource;
use Filament\Actions\CreateAction;

class ListTicketBlockedSenders extends BaseListRecords
{
    protected static string $resource = TicketBlockedSenderResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
