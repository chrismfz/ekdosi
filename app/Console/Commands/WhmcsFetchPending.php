<?php

namespace App\Console\Commands;

use App\Exceptions\Whmcs\WhmcsApiException;
use App\Exceptions\Whmcs\WhmcsAuthenticationFailed;
use App\Exceptions\Whmcs\WhmcsNotConfigured;
use App\Exceptions\Whmcs\WhmcsUnreachable;
use App\Models\Company;
use App\Services\Whmcs\WhmcsClient;
use App\Services\Whmcs\WhmcsClientFactory;
use App\Services\Whmcs\WhmcsCustomerMatcher;
use App\Services\Whmcs\WhmcsInvoiceIngestor;
use Illuminate\Console\Command;

/**
 * PR #31 (WHMCS bridge - Stage B-1): fetch + stage WHMCS invoices into
 * pending_whmcs_invoices for operator review.
 *
 *   php artisan whmcs:fetch-pending --tenant=myip
 *
 * Default behaviour: ingestion. For each paid+unfiled WHMCS invoice
 * (the same set Stage A's preview iterated), call WhmcsClient::getInvoice
 * to fetch the rich payload, then hand it to WhmcsInvoiceIngestor.
 * Idempotent across re-runs - the upsert key is
 * (company_id, whmcs_invoice_id).
 *
 * Replaces the old Stage A command `whmcs:pull-pending-invoices`,
 * which only printed a dry-run table. The old dry-run is still
 * available via the --preview flag (no DB writes, no per-invoice
 * GetInvoice calls).
 *
 *   php artisan whmcs:fetch-pending --tenant=myip --preview
 *
 * Exit codes (unchanged from Stage A so existing scheduler wrappers
 * don't break, with new codes appended):
 *   0  success
 *   1  generic error (parse, DB, unexpected throw)
 *   2  invalid usage (Command::INVALID)
 *   3  WHMCS not configured for tenant
 *   4  WHMCS authentication failed
 *   5  WHMCS unreachable
 *   6  unknown tenant slug
 *   7  partial success - at least one invoice failed to stage (Stage B-1)
 *
 * Nothing in this command files at AADE. That's Stage B-2.
 */
class WhmcsFetchPending extends Command
{
    protected $signature = 'whmcs:fetch-pending
        {--tenant= : Company slug. Required.}
        {--limit=100 : Max WHMCS-side rows to fetch (WHMCS caps at 100).}
        {--offset=0 : Offset for paginating through larger result sets.}
        {--preview : Read-only dry-run; print the table from Stage A, do not stage anything.}';

    protected $description = 'WHMCS bridge: fetch paid+unfiled invoices and stage them in pending_whmcs_invoices for operator review. --preview for the Stage A read-only table.';

    public function handle(
        WhmcsClientFactory $factory,
        WhmcsCustomerMatcher $matcher,
        WhmcsInvoiceIngestor $ingestor,
    ): int {
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

        $this->info("Tenant: {$tenant->name} (slug={$tenant->slug})");

        try {
            $client = $factory->for($tenant);
        } catch (WhmcsNotConfigured $e) {
            $this->error($e->getMessage());
            return 3;
        }

        try {
            // Honour the per-tenant cutoff date so a long-running
            // tenant (myip has invoices from 2007) doesn't drown the
            // inbox in historical test rows. Null = no cutoff.
            $minDate = $tenant->whmcs_invoice_min_date?->format('Y-m-d');
            $invoices = $client->getPendingInvoices(
                limit: (int) $this->option('limit'),
                offset: (int) $this->option('offset'),
                minDate: $minDate,
            );
        } catch (WhmcsAuthenticationFailed $e) {
            $this->error("WHMCS authentication failed: {$e->getMessage()}");
            $this->line('Check Setup → Staff Management → API Credentials on the WHMCS side.');
            return 4;
        } catch (WhmcsUnreachable $e) {
            $this->error("WHMCS unreachable: {$e->getMessage()}");
            return 5;
        } catch (WhmcsApiException $e) {
            $this->error("WHMCS error: {$e->getMessage()}");
            return Command::FAILURE;
        }

        if (empty($invoices)) {
            $this->info('No paid+unfiled invoices pending for this tenant. Nothing to do.');
            return Command::SUCCESS;
        }

        if ((bool) $this->option('preview')) {
            return $this->renderPreviewTable($tenant, $matcher, $invoices);
        }

        return $this->ingestAll($tenant, $client, $ingestor, $invoices);
    }

    /**
     * Default behaviour: fetch the rich payload per invoice id and
     * hand it to the ingestor. ONE GetInvoice call per row - that's
     * N+1 against the WHMCS API but it's the only way to capture
     * the line items / full client identity that Stage B-2's
     * File-at-AADE action needs to build an ekdosi Invoice.
     *
     * @param  array<int, array<string, mixed>>  $listShapeInvoices
     */
    private function ingestAll(
        Company $tenant,
        WhmcsClient $client,
        WhmcsInvoiceIngestor $ingestor,
        array $listShapeInvoices,
    ): int {
        $this->line('');
        $this->info(sprintf('Found %d paid+unfiled invoice(s). Staging...', count($listShapeInvoices)));

        $created = 0;
        $updated = 0;
        $auditPreserved = 0;
        $failed = 0;

        foreach ($listShapeInvoices as $listRow) {
            $invoiceId = (int) ($listRow['id'] ?? 0);
            if ($invoiceId <= 0) {
                $this->warn('Skipped row with no id.');
                $failed++;
                continue;
            }

            try {
                // getInvoiceWithClient enriches with the client's
                // customfields so the ingestor's matcher can resolve
                // AFM matches. Cost: 2 API calls per row (1+2N total
                // for the batch).
                $payload = $client->getInvoiceWithClient($invoiceId);
                if ($payload === null) {
                    $this->warn("WHMCS invoice #{$invoiceId}: not found on GetInvoice (deleted since GetInvoices?).");
                    $failed++;
                    continue;
                }

                $result = $ingestor->ingest($tenant, $payload);

                if ($result->created) {
                    $created++;
                    $this->line(sprintf(
                        '  staged  WHMCS#%d -> pending #%d (match: %s)',
                        $invoiceId,
                        $result->row->id,
                        $result->row->match_reason,
                    ));
                } elseif ($result->auditPreserved) {
                    $auditPreserved++;
                    $this->line(sprintf(
                        '  frozen  WHMCS#%d -> pending #%d (already filed; payload preserved)',
                        $invoiceId,
                        $result->row->id,
                    ));
                } else {
                    $updated++;
                    $this->line(sprintf(
                        '  refresh WHMCS#%d -> pending #%d (status: %s)',
                        $invoiceId,
                        $result->row->id,
                        $result->row->status,
                    ));
                }
            } catch (WhmcsAuthenticationFailed $e) {
                // Tenant-fatal: WHMCS has rejected our credentials. Every
                // remaining invoice will fail the same way, possibly
                // wedging the run for N * 20s timeouts. Abort with the
                // dedicated auth exit code so cron wrappers route to
                // the right alert. Subclass catch MUST come before
                // the WhmcsApiException catch below.
                $this->error("Aborting batch: WHMCS authentication failed mid-loop - {$e->getMessage()}");
                $this->line('Check Setup → Staff Management → API Credentials on the WHMCS side, plus the IP allowlist.');
                $this->line(sprintf('Partial result: %d created, %d refreshed, %d audit-frozen, %d failed before abort.', $created, $updated, $auditPreserved, $failed));
                return 4;
            } catch (WhmcsUnreachable $e) {
                // Tenant-fatal: WHMCS host unreachable. Same reasoning -
                // the rest of the batch cannot succeed.
                $this->error("Aborting batch: WHMCS unreachable mid-loop - {$e->getMessage()}");
                $this->line(sprintf('Partial result: %d created, %d refreshed, %d audit-frozen, %d failed before abort.', $created, $updated, $auditPreserved, $failed));
                return 5;
            } catch (WhmcsApiException $e) {
                $this->warn("WHMCS invoice #{$invoiceId}: API error - {$e->getMessage()}");
                $failed++;
            } catch (\Throwable $e) {
                $this->warn("WHMCS invoice #{$invoiceId}: ingest failed - {$e->getMessage()}");
                $failed++;
            }
        }

        $this->line('');
        $this->info(sprintf(
            'Summary: %d created, %d refreshed, %d audit-frozen, %d failed.',
            $created, $updated, $auditPreserved, $failed,
        ));

        // Partial-success exit code lets a scheduler wrapper distinguish
        // "everything worked" from "some rows need a human" without
        // grepping output. Code 7 is appended to Stage A's range
        // (0-6) so existing wrappers that only branch on 0 vs nonzero
        // keep working.
        return $failed > 0 ? 7 : Command::SUCCESS;
    }

    /**
     * --preview path: the Stage A read-only table. Identical output
     * shape so anyone with muscle memory still gets the same view.
     * Does NOT call GetInvoice per row - cheap probe of the WHMCS API.
     *
     * @param  array<int, array<string, mixed>>  $invoices
     */
    private function renderPreviewTable(
        Company $tenant,
        WhmcsCustomerMatcher $matcher,
        array $invoices,
    ): int {
        $this->line('');
        $this->info(sprintf('Found %d paid+unfiled invoice(s).', count($invoices)));
        $this->warn('DRY RUN (--preview) - nothing will be staged or filed.');

        $rows = [];
        $matchedCount = 0;
        $unmatchedCount = 0;

        foreach ($invoices as $inv) {
            $whmcsClientId = (int) ($inv['userid'] ?? 0);
            $match = $matcher->match($tenant, [
                'id'          => $whmcsClientId,
                'userid'      => $whmcsClientId,
                'email'       => $inv['email'] ?? null,
                'firstname'   => $inv['firstname'] ?? null,
                'lastname'    => $inv['lastname'] ?? null,
                'companyname' => $inv['companyname'] ?? null,
            ]);

            if ($match->isMatched()) {
                $matchedCount++;
            } else {
                $unmatchedCount++;
            }

            $rows[] = [
                $inv['id'] ?? '?',
                $inv['date'] ?? '?',
                $whmcsClientId,
                ($inv['companyname'] ?? '')
                    ?: trim(($inv['firstname'] ?? '').' '.($inv['lastname'] ?? '')),
                number_format((float) ($inv['total'] ?? 0), 2, ',', '.').' '.($inv['currencycode'] ?? ''),
                $match->isMatched() ? "#{$match->customer->id} ({$match->customer->name})" : '—',
                $match->confidenceLabel(),
            ];
        }

        $this->table(
            ['WHMCS inv', 'Date', 'Client', 'Name', 'Total', 'ekdosi customer', 'Match reason'],
            $rows,
        );

        $this->line('');
        $this->info("Summary: {$matchedCount} matched, {$unmatchedCount} unmatched.");
        if ($unmatchedCount > 0) {
            $this->warn('Unmatched rows need a customer link before Stage B-2 can file them.');
            $this->line('Use the Filament Customer resource → "Link to WHMCS" action to map manually.');
        }

        return Command::SUCCESS;
    }
}
