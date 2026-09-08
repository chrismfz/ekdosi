<?php

namespace App\Filament\Resources\InvoiceTypes\Pages;

use App\Filament\Resources\InvoiceTypes\InvoiceTypeResource;
use Filament\Resources\Pages\CreateRecord;

class CreateInvoiceType extends CreateRecord
{
    protected static string $resource = InvoiceTypeResource::class;
}
