<?php

namespace App\Services\Whmcs;

use App\Models\PendingWhmcsInvoice;

/**
 * PR #31 (Stage B-1): outcome of WhmcsInvoiceIngestor::ingest().
 *
 * Returned to both callers (artisan command + webhook controller) so
 * each can render the right status code / log line WITHOUT having to
 * re-query the model to determine whether this was a create vs. update
 * vs. audit-preserved no-op.
 */
final readonly class IngestionResult
{
    public function __construct(
        public PendingWhmcsInvoice $row,
        /** True if a new pending_whmcs_invoices row was inserted. */
        public bool $created,
        /** True if the row was already in status=filed and the payload
         *  was preserved (NOT refreshed). The HTTP layer uses this to
         *  return a distinct status code so the WHMCS-side plugin can
         *  tell "we already filed this; stop retrying" from "fresh
         *  staged for review". */
        public bool $auditPreserved,
    ) {
    }
}
