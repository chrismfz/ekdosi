<?php

namespace App\Filament\Resources\InvoiceMailLogs\Pages;

use App\Filament\BaseListRecords;
use App\Filament\Resources\InvoiceMailLogs\InvoiceMailLogResource;

class ListInvoiceMailLogs extends BaseListRecords
{
    protected static string $resource = InvoiceMailLogResource::class;

    // Read-only log — no create header action.
    protected function getHeaderActions(): array
    {
        return [];
    }
}
