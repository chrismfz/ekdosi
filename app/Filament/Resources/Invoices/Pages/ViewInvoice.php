<?php

namespace App\Filament\Resources\Invoices\Pages;

use App\Filament\Resources\Invoices\InvoiceResource;
use Filament\Resources\Pages\ViewRecord;

/**
 * Read-only view page. No header actions — no Edit, no Delete, no
 * Cancel-via-myDATA, no Submit-to-myDATA, no PDF download. All of
 * those land in later PRs (#7 Submitter + #8 IssueInvoice).
 */
class ViewInvoice extends ViewRecord
{
    protected static string $resource = InvoiceResource::class;
}
