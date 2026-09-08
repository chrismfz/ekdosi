<?php

namespace App\Services\WhmcsInbox;

use App\Models\Invoice;
use App\Models\PendingWhmcsInvoice;

/**
 * Return shape from WhmcsInvoiceFiler::file(). The page's action
 * callback uses this to produce a notification with the ekdosi
 * invoice number + MARK + a link to the View page.
 */
final readonly class FileResult
{
    public function __construct(
        public Invoice $invoice,
        public ?string $mark,                 // null when off-mode tenants
        public PendingWhmcsInvoice $pending,
    ) {
    }
}
