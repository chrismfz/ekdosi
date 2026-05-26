<?php

namespace App\Filament\Resources\Invoices\Pages;

use App\Filament\Resources\Invoices\InvoiceResource;
use Filament\Resources\Pages\ListRecords;

/**
 * Read-only list page. No CreateAction in the header — operators
 * can't issue invoices yet (lands in PR #8). The view page is the
 * only navigation target from a row.
 */
class ListInvoices extends ListRecords
{
    protected static string $resource = InvoiceResource::class;
}
