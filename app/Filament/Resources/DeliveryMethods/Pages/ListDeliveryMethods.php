<?php

namespace App\Filament\Resources\DeliveryMethods\Pages;

use App\Filament\Resources\DeliveryMethods\DeliveryMethodResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListDeliveryMethods extends ListRecords
{
    protected static string $resource = DeliveryMethodResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
