<?php

namespace App\Console\Commands;

use App\Exceptions\Whmcs\WhmcsApiException;
use App\Exceptions\Whmcs\WhmcsNotConfigured;
use App\Exceptions\Whmcs\WhmcsUnreachable;
use App\Models\Company;
use App\Services\Whmcs\WhmcsBridgeClientFactory;
use Illuminate\Console\Command;

/**
 * Stage 3 (Plugin-API consolidation): flip a tenant's invoice SOURCE between the
 * ekdosi_bridge plugin (resolve.php — the Plugin-API) and the native WHMCS API.
 *
 *   php artisan whmcs:use-bridge --tenant=myip          # enable (after a SAFE probe)
 *   php artisan whmcs:use-bridge --tenant=myip --off     # revert to the native API
 *
 * Enabling is GUARDED: we probe the deployed plugin for op=invoice support before
 * flipping `companies.whmcs_fetch_via_bridge`. This closes the deploy-ordering
 * trap — if the WHMCS-side plugin is older than v0.32.0 (no op=invoice), the push
 * path would break; the probe catches that and refuses to flip, telling the
 * operator to deploy the plugin first. Reversible at any time with --off.
 *
 * The flag drives BOTH the inbox pull (whmcs:fetch-pending) and the push webhook.
 * Tenants without the plugin (no derivable bridge URL / secret) simply stay on
 * the native API — that's the "API only when there's no plugin" policy.
 *
 * Exit codes: 0 ok · 2 invalid usage · 3 bridge not configured · 5 unreachable
 *             · 6 unknown tenant · 1 plugin too old / probe failed.
 */
class WhmcsUseBridge extends Command
{
    protected $signature = 'whmcs:use-bridge
        {--tenant= : Company slug. Required.}
        {--off : Disable bridge fetch and revert this tenant to the native WHMCS API.}';

    protected $description = 'Switch a tenant between the ekdosi_bridge Plugin-API and the native WHMCS API for invoice fetch (pull + push). Probes plugin support before enabling.';

    public function handle(WhmcsBridgeClientFactory $factory): int
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

        if ((bool) $this->option('off')) {
            $tenant->forceFill(['whmcs_fetch_via_bridge' => false])->save();
            $this->info("Tenant {$tenant->slug}: bridge fetch DISABLED — now uses the native WHMCS API.");

            return Command::SUCCESS;
        }

        // Enabling — confirm the bridge is configured AND the deployed plugin
        // actually serves op=invoice, so we never flip into a broken push path.
        try {
            $bridge = $factory->for($tenant);
        } catch (WhmcsNotConfigured $e) {
            $this->error("Bridge not configured for {$tenant->slug}: {$e->getMessage()}");
            $this->line('Set whmcs_api_url (…/includes/api.php) AND whmcs_webhook_secret in Company → WHMCS first.');

            return 3;
        }

        $this->info('Probing the ekdosi_bridge plugin for op=invoice support…');
        try {
            // A benign single-invoice probe: a real id returns its payload, an
            // unknown id returns null — BOTH prove op=invoice is served. Only a
            // throw (old plugin → unknown_op, or transport/auth failure) blocks.
            $bridge->fetchInvoice(1);
        } catch (WhmcsUnreachable $e) {
            $this->error("Bridge unreachable: {$e->getMessage()}");

            return 5;
        } catch (WhmcsApiException $e) {
            if (str_contains($e->getMessage(), 'unknown_op')) {
                $this->error('The deployed ekdosi_bridge plugin is too old (no op=invoice).');
                $this->line('Deploy plugin v0.32.0+ on the WHMCS side first, then re-run this command.');
            } else {
                $this->error("Bridge probe failed: {$e->getMessage()}");
            }

            return Command::FAILURE;
        }

        $tenant->forceFill(['whmcs_fetch_via_bridge' => true])->save();
        $this->info("Tenant {$tenant->slug}: bridge fetch ENABLED — pull + push now use the Plugin-API (resolve.php).");

        return Command::SUCCESS;
    }
}
