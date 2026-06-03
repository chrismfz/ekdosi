<?php

namespace App\Filament\Resources\DeliveryNotes\Pages;

use App\Filament\BaseListRecords;
use App\Filament\Resources\DeliveryNotes\DeliveryNoteResource;
use Filament\Actions\CreateAction;

class ListDeliveryNotes extends BaseListRecords
{
    protected static string $resource = DeliveryNoteResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()->label('+ Νέο Δελτίο Αποστολής'),
        ];
    }
}
