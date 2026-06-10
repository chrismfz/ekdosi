<?php

namespace App\Filament\Resources\DeliveryMethods\Pages;

use App\Filament\Resources\DeliveryMethods\DeliveryMethodResource;
use App\Filament\Support\GuardedDeleteAction;
use App\Models\DeliveryNote;
use App\Models\Invoice;
use App\Models\InvoiceType;
use Filament\Resources\Pages\EditRecord;

class EditDeliveryMethod extends EditRecord
{
    protected static string $resource = DeliveryMethodResource::class;

    protected function getHeaderActions(): array
    {
        return [
            GuardedDeleteAction::make(fn ($record): array => [
                'τιμολόγια' => GuardedDeleteAction::count(Invoice::class, 'delivery_method_id', $record->id),
                'δελτία αποστολής' => GuardedDeleteAction::count(DeliveryNote::class, 'delivery_method_id', $record->id),
                'τύποι παραστατικών (προεπιλογή)' => GuardedDeleteAction::count(InvoiceType::class, 'delivery_method_id', $record->id),
            ]),
        ];
    }
}
