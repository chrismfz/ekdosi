<?php

namespace App\Console\Commands;

use App\Exceptions\Whmcs\WhmcsApiException;
use App\Exceptions\Whmcs\WhmcsAuthenticationFailed;
use App\Exceptions\Whmcs\WhmcsNotConfigured;
use App\Exceptions\Whmcs\WhmcsUnreachable;
use App\Models\Company;
use App\Services\Whmcs\WhmcsClientFactory;
use App\Services\Whmcs\WhmcsCustomerMatcher;
use Illuminate\Console\Command;

/**
 * PR #28 (WHMCS bridge — Stage A): operator-driven dry-run preview.
 *
 *   php artisan whmcs:pull-pending-invoices --tenant=myip
 *
 * Lists every WHMCS invoice that's Paid + unfiled (invoiced=0) for
 * the given tenant, matched against ekdosi customers. Prints a
 * table; does NOT issue or modify ANYTHING. This is the verification
 * step before Stage B (PR #29) hooks the same pull into the
 * IssueInvoice action.
 *
 * Exit codes (so a cron wrapper can route alerts):
 *   0  success — preview rendered (may include unmatched rows)
 *   1  generic error (parse, DB, unexpected throw)
 *   2  tenant not found
 *   3  WHMCS not configured for tenant
 *   4  WHMCS authentication failed
 *   5  WHMCS unreachable
 *
 * The differentiated exit codes let the scheduled wrapper distinguish
 * "broken config, page operator" from "transient network blip, retry
 * next tick" — Stage B builds on this.
 */
class WhmcsPullPendingInvoices extends Command
{
    protected $signature = 'whmcs:pull-pending-invoices
        {--tenant= : Company slug. Required.}
        {--limit=100 : Max WHMCS-side rows to fetch (WHMCS caps at 100).}
        {--offset=0 : Offset for paginating through larger result sets.}';

    protected $description = 'WHMCS bridge dry-run: list paid+unfiled WHMCS invoices for a tenant, matched against ekdosi customers. Read-only — never issues anything.';

    public function handle(
        WhmcsClientFactory $factory,
        WhmcsCustomerMatcher $matcher,
    ): int {
        $slug = (string) $this->option('tenant');
        if ($slug === '') {
            $this->error('--tenant=SLUG is required.');
            return Command::INVALID;
        }

        $tenant = Company::query()->where('slug', $slug)->first();
        if ($tenant === null) {
            $this->error("No tenant with slug='{$slug}'.");
            return 2;
        }

        $this->info("Tenant: {$tenant->name} (slug={$tenant->slug})");

        try {
            $client = $factory->for($tenant);
        } catch (WhmcsNotConfigured $e) {
            $this->error($e->getMessage());
            return 3;
        }

        try {
            $invoices = $client->getPendingInvoices(
                limit: (int) $this->option('limit'),
                offset: (int) $this->option('offset'),
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
            $this->info('No paid+unfiled invoices pending for this tenant. Nothing to preview.');
            return Command::SUCCESS;
        }

        $this->line('');
        $this->info(sprintf(
            'Found %d paid+unfiled invoice(s).',
            count($invoices)
        ));
        $this->warn('DRY RUN — nothing will be issued.');

        $rows = [];
        $matchedCount = 0;
        $unmatchedCount = 0;

        foreach ($invoices as $inv) {
            $whmcsClientId = (int) ($inv['userid'] ?? 0);
            // WHMCS's GetInvoices response carries minimal client
            // identity; for the AFM/email match we'd ideally call
            // GetClientsDetails per row, but at scale that's N+1
            // calls. For the preview, we match on what's in the
            // invoice row + a direct whmcs_client_id link only.
            // Stage B will batch-fetch client details when it
            // actually issues.
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
            $this->warn('Unmatched rows need a customer link before Stage B can issue them.');
            $this->line('Use the Filament Customer resource → "Link to WHMCS" action to map manually.');
        }

        return Command::SUCCESS;
    }
}
