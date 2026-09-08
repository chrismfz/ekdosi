<?php

namespace App\Filament\Resources\DeliveryMethods\Pages;

use App\Filament\Resources\DeliveryMethods\DeliveryMethodResource;
use App\Filament\Support\GuardedDeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditDeliveryMethod extends EditRecord
{
    protected static string $resource = DeliveryMethodResource::class;

    protected function getHeaderActions(): array
    {
        return [
            GuardedDeleteAction::make(fn ($record): array => DeliveryMethodResource::dependents($record)),
        ];
    }
}
