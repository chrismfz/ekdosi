<?php

namespace App\Filament\Resources\InvoiceTypes\Pages;

use App\Filament\Resources\InvoiceTypes\InvoiceTypeResource;
use App\Filament\Support\GuardedDeleteAction;
use App\Models\Company;
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
                'τιμολόγια' => GuardedDeleteAction::count(Invoice::class, 'invoice_type_id', $record->id),
                'δελτία αποστολής' => GuardedDeleteAction::count(DeliveryNote::class, 'delivery_type_id', $record->id),
                'συμβόλαια' => GuardedDeleteAction::count(ServiceContract::class, 'invoice_type_id', $record->id),
                'εταιρίες (προεπιλογή WHMCS)' => GuardedDeleteAction::count(Company::class, 'whmcs_default_invoice_type_id', $record->id),
            ]),
        ];
    }
}
