<?php

namespace App\Filament\Resources\Invoices\Pages;

use App\Filament\Resources\Invoices\InvoiceResource;
use App\Filament\Support\Tags\TagControls;
use App\Models\Invoice;
use Filament\Actions\CreateAction;
use App\Filament\BaseListRecords;

class ListInvoices extends BaseListRecords
{
    protected static string $resource = InvoiceResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()->label('+ Νέο Παραστατικό'),
        ];
    }

    public function getTabs(): array
    {
        return TagControls::pinnedTabs(Invoice::class);
    }
}
