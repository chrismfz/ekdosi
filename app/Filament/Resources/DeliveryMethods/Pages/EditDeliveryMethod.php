<?php

namespace App\Filament\Resources\DeliveryMethods\Pages;

use App\Filament\Resources\DeliveryMethods\DeliveryMethodResource;
use App\Filament\Support\GuardedDeleteAction;
use App\Models\DeliveryNote;
use App\Models\Invoice;
use Filament\Resources\Pages\EditRecord;

class EditDeliveryMethod extends EditRecord
{
    protected static string $resource = DeliveryMethodResource::class;

    protected function getHeaderActions(): array
    {
        return [
            GuardedDeleteAction::make(fn ($record): array => [
                'τιμολόγια' => Invoice::where('delivery_method_id', $record->id)->count(),
                'δελτία αποστολής' => DeliveryNote::where('delivery_method_id', $record->id)->count(),
            ]),
        ];
    }
}
