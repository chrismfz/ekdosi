<?php

namespace App\Console\Commands;

use App\Exceptions\Whmcs\WhmcsApiException;
use App\Exceptions\Whmcs\WhmcsNotConfigured;
use App\Exceptions\Whmcs\WhmcsUnreachable;
use App\Models\Company;
use App\Models\Invoice;
use App\Services\Whmcs\WhmcsBridgeClientFactory;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Historical, deterministic WHMCS↔ekdosi link backfill.
 *
 * The legacy auto-invoicer (FAutoInvoice.cpp) wrote the legacy ekdosi
 * INVOICE_ID into WHMCS `tblinvoices.invoiced`; the ETL kept that same id as
 * `invoices.legacy_id`. So `tblinvoices.invoiced === invoices.legacy_id` is an
 * EXACT key (not the heuristic content-match that was removed). This command
 * pages the bridge's read-only `legacy_invoice_links` op and stamps
 * `invoices.whmcs_invoice_id` on the matching imported invoice, so ekdosi knows
 * which WHMCS invoice each historical παραστατικό came from.
 *
 *   php artisan whmcs:backfill-invoice-ids --tenant=myip [--dry-run]
 *
 * Re-runnable / idempotent: a row already carrying the right whmcs_invoice_id is
 * left untouched (not counted). Tenant-scoped (legacy_id is unique per company).
 * Degrades cleanly when the bridge isn't configured/reachable.
 */
class WhmcsBackfillInvoiceIds extends Command
{
    protected $signature = 'whmcs:backfill-invoice-ids
        {--tenant= : Company slug. Required.}
        {--limit=500 : Page size for the bridge legacy_invoice_links op.}
        {--dry-run : Report what would change without writing.}';

    protected $description = 'Backfill invoices.whmcs_invoice_id from the legacy tblinvoices.invoiced→legacy_id link (deterministic, historical).';

    public function handle(WhmcsBridgeClientFactory $bridgeFactory): int
    {
        $slug = (string) $this->option('tenant');
        if ($slug === '') {
            $this->error('--tenant=SLUG is required.');

            return Command::INVALID;
        }

        $tenant = Company::query()->where('slug', $slug)->first();
        if ($tenant === null) {
            $this->error("No tenant with slug='{$slug}'.");

            return 6;
        }

        try {
            $bridge = $bridgeFactory->for($tenant);
        } catch (WhmcsNotConfigured $e) {
            $this->error($e->getMessage());

            return 3;
        }

        $dryRun = (bool) $this->option('dry-run');
        $limit = max(1, (int) $this->option('limit'));
        $this->info("Tenant: {$tenant->name} (slug={$tenant->slug})".($dryRun ? '  [DRY RUN]' : ''));

        $offset = 0;
        $seenLinks = 0;
        $matched = 0;
        $updated = 0;
        $alreadyLinked = 0;
        $unmatched = 0;

        while (true) {
            try {
                $links = $bridge->getLegacyInvoiceLinks($offset, $limit);
            } catch (WhmcsUnreachable|WhmcsApiException $e) {
                $this->error('Bridge error at offset '.$offset.': '.$e->getMessage());

                return Command::FAILURE;
            }

            if ($links === []) {
                break;   // end of pages
            }
            $seenLinks += count($links);

            // invoiced (legacy_id) => whmcs_id for this page.
            $byLegacyId = [];
            foreach ($links as $link) {
                $byLegacyId[$link['invoiced']] = $link['whmcs_id'];
            }

            // One query per page: the matching ekdosi invoices for this tenant.
            $invoices = Invoice::query()
                ->where('company_id', $tenant->id)
                ->whereIn('legacy_id', array_keys($byLegacyId))
                ->get(['id', 'legacy_id', 'whmcs_invoice_id']);

            foreach ($invoices as $invoice) {
                $matched++;
                $targetWhmcsId = $byLegacyId[$invoice->legacy_id] ?? null;
                if ($targetWhmcsId === null) {
                    continue;
                }
                if ((int) $invoice->whmcs_invoice_id === $targetWhmcsId) {
                    $alreadyLinked++;

                    continue;
                }
                if (! $dryRun) {
                    // forceFill: whmcs_invoice_id is an internal link, not in
                    // $fillable. Update only this column.
                    DB::table('invoices')
                        ->where('id', $invoice->id)
                        ->update(['whmcs_invoice_id' => $targetWhmcsId]);
                }
                $updated++;
            }

            // Legacy ids on this page with no matching ekdosi invoice (created
            // manually in legacy, or outside this tenant) — informational.
            $unmatched += count($byLegacyId) - $invoices->count();

            $offset += count($links);
            if (count($links) < $limit) {
                break;   // short page = last page
            }
        }

        $this->line('');
        $this->info(sprintf(
            'Legacy-filed WHMCS invoices seen: %d | matched ekdosi invoices: %d | %s: %d | already linked: %d | unmatched legacy ids: %d',
            $seenLinks,
            $matched,
            $dryRun ? 'would update' : 'updated',
            $updated,
            $alreadyLinked,
            $unmatched,
        ));

        return Command::SUCCESS;
    }
}
