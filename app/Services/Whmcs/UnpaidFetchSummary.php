<?php

namespace App\Services\Whmcs;

/**
 * Outcome of one {@see WhmcsUnpaidFetcher::fetch()} run for a tenant.
 *
 * - customers        = flagged «τιμολόγιο-πριν-την-πληρωμή» customers processed (with a WHMCS link)
 * - skippedNoLink    = flagged customers WITHOUT a whmcs_client_id (can't be fetched — operator must link)
 * - unpaidSeen       = UNPAID WHMCS invoices found across those customers
 * - created/updated  = pending_whmcs_invoices rows newly staged / refreshed
 * - failed           = unpaid invoices whose per-row fetch/ingest threw (logged, skipped)
 */
final readonly class UnpaidFetchSummary
{
    public function __construct(
        public int $customers = 0,
        public int $skippedNoLink = 0,
        public int $unpaidSeen = 0,
        public int $created = 0,
        public int $updated = 0,
        public int $failed = 0,
    ) {}
}
