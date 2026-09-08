<?php

namespace App\Filament\Resources\InvoiceTypes\Pages;

use App\Filament\Resources\InvoiceTypes\InvoiceTypeResource;
use App\Filament\Support\GuardedDeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditInvoiceType extends EditRecord
{
    protected static string $resource = InvoiceTypeResource::class;

    protected function getHeaderActions(): array
    {
        return [
            GuardedDeleteAction::make(fn ($record): array => InvoiceTypeResource::dependents($record)),
        ];
    }
}
