<?php

namespace App\Filament\Resources\DeliveryMethods\Pages;

use App\Filament\Resources\DeliveryMethods\DeliveryMethodResource;
use Filament\Actions\CreateAction;
use App\Filament\BaseListRecords;

class ListDeliveryMethods extends BaseListRecords
{
    protected static string $resource = DeliveryMethodResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
