<?php

namespace App\Filament\Resources\InvoiceTypes\Pages;

use App\Filament\Resources\InvoiceTypes\InvoiceTypeResource;
use App\Filament\Support\GuardedDeleteAction;
use App\Models\DeliveryNote;
use App\Models\Invoice;
use App\Models\ServiceContract;
use Filament\Resources\Pages\EditRecord;

class EditInvoiceType extends EditRecord
{
    protected static string $resource = InvoiceTypeResource::class;

    protected function getHeaderActions(): array
    {
        return [
            GuardedDeleteAction::make(fn ($record): array => [
                'τιμολόγια' => Invoice::where('invoice_type_id', $record->id)->count(),
                'δελτία αποστολής' => DeliveryNote::where('delivery_type_id', $record->id)->count(),
                'συμβόλαια' => ServiceContract::where('invoice_type_id', $record->id)->count(),
            ]),
        ];
    }
}
