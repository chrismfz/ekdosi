<?php

namespace App\Console\Commands;

use App\Exceptions\Whmcs\WhmcsApiException;
use App\Exceptions\Whmcs\WhmcsNotConfigured;
use App\Exceptions\Whmcs\WhmcsUnreachable;
use App\Models\Company;
use App\Models\Customer;
use App\Services\Whmcs\WhmcsBridgeClientFactory;
use Illuminate\Console\Command;

/**
 * T-1b (timologia v2, feature #6): mirror the WHMCS third-party routing counts
 * onto ekdosi Customers so the operator can spot, at a glance, which customers
 * route some of their invoices to third parties ("Παραστατικά σε τρίτους").
 *
 *   php artisan whmcs:sync-resellers --tenant=myip
 *
 * READ-ONLY against WHMCS (calls the bridge's resolve.php op=resellers). On the
 * ekdosi side it only writes customers.whmcs_reseller_routes — the badge count.
 * Customers are matched by customers.whmcs_client_id (the operator-set link);
 * counts for clients with no linked Customer are reported but not stored.
 * Idempotent: every linked customer's count is reset, then set from the live
 * data, so de-routed customers correctly drop back to 0.
 *
 * Exit codes mirror the other whmcs:* commands:
 *   0 success / 1 API error / 2 invalid usage / 3 not configured
 *   5 unreachable / 6 unknown tenant
 */
class WhmcsSyncResellers extends Command
{
    protected $signature = 'whmcs:sync-resellers
        {--tenant= : Company slug. Required.}';

    protected $description = 'WHMCS bridge (T-1): mirror third-party routing counts onto customers.whmcs_reseller_routes (the reseller badge).';

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

        try {
            $resellers = $factory->for($tenant)->listResellers();
        } catch (WhmcsNotConfigured $e) {
            $this->error($e->getMessage());

            return 3;
        } catch (WhmcsUnreachable $e) {
            $this->error("Bridge unreachable: {$e->getMessage()}");

            return 5;
        } catch (WhmcsApiException $e) {
            $this->error("Bridge error: {$e->getMessage()}");

            return Command::FAILURE;
        }

        // Reset all of this tenant's customers to 0 first, so a customer who
        // stopped routing (removed all their mod_timologia rows) loses the
        // badge on the next sync.
        Customer::query()->where('company_id', $tenant->id)->update(['whmcs_reseller_routes' => 0]);

        $linked = 0;
        $orphans = 0;
        foreach ($resellers as $reseller) {
            $affected = Customer::query()
                ->where('company_id', $tenant->id)
                ->where('whmcs_client_id', $reseller['userid'])
                ->update(['whmcs_reseller_routes' => $reseller['routes']]);

            if ($affected > 0) {
                $linked++;
            } else {
                $orphans++;
                $this->warn(sprintf(
                    'WHMCS client %d routes %d service(s) but has no linked ekdosi Customer (whmcs_client_id). Link it to see the badge.',
                    $reseller['userid'],
                    $reseller['routes'],
                ));
            }
        }

        $this->info(sprintf(
            'Synced reseller flags for %s: %d customer(s) flagged, %d WHMCS reseller(s) with no linked customer.',
            $tenant->slug,
            $linked,
            $orphans,
        ));

        return Command::SUCCESS;
    }
}
