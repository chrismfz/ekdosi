<?php

namespace App\Console\Commands;

use App\Exceptions\Whmcs\WhmcsApiException;
use App\Exceptions\Whmcs\WhmcsNotConfigured;
use App\Exceptions\Whmcs\WhmcsUnreachable;
use App\Models\Company;
use App\Services\Whmcs\ThirdPartyResolution;
use App\Services\Whmcs\WhmcsBridgeClientFactory;
use Illuminate\Console\Command;

/**
 * T-1a (timologia v2): READ-ONLY diagnostic for third-party invoicing.
 *
 * Validates the bridge's resolve.php against live WHMCS routing data without
 * touching ekdosi's filing path at all — exactly the "test live, customers
 * notice nothing" probe before we wire billing (T-1b).
 *
 *   php artisan whmcs:resolve-third-party 1234 --tenant=myip
 *     → per-line routing for WHMCS invoice #1234.
 *
 *   php artisan whmcs:resolve-third-party --tenant=myip --resellers
 *     → list every WHMCS client that routes some service to a third party.
 *
 * Exit codes mirror whmcs:fetch-pending so scheduler wrappers stay consistent:
 *   0  success
 *   1  generic / API error
 *   2  invalid usage
 *   3  bridge not configured for tenant
 *   5  bridge unreachable
 *   6  unknown tenant slug
 */
class WhmcsResolveThirdParty extends Command
{
    protected $signature = 'whmcs:resolve-third-party
        {invoice? : WHMCS invoice id to resolve (omit with --resellers).}
        {--tenant= : Company slug. Required.}
        {--resellers : List WHMCS clients with >=1 third-party routing row instead of resolving one invoice.}';

    protected $description = 'WHMCS bridge (T-1): read-only third-party-invoicing resolution — per-invoice line routing, or the reseller list.';

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

        $resellersMode = (bool) $this->option('resellers');
        $invoiceArg = $this->argument('invoice');
        if (! $resellersMode && ($invoiceArg === null || (int) $invoiceArg <= 0)) {
            $this->error('Provide a WHMCS invoice id, or use --resellers.');

            return Command::INVALID;
        }

        $this->info("Tenant: {$tenant->name} (slug={$tenant->slug})");

        try {
            $bridge = $factory->for($tenant);
        } catch (WhmcsNotConfigured $e) {
            $this->error($e->getMessage());

            return 3;
        }

        try {
            return $resellersMode
                ? $this->renderResellers($bridge->listResellers())
                : $this->renderResolution($bridge->resolveThirdParty((int) $invoiceArg));
        } catch (WhmcsUnreachable $e) {
            $this->error("Bridge unreachable: {$e->getMessage()}");

            return 5;
        } catch (WhmcsApiException $e) {
            $this->error("Bridge error: {$e->getMessage()}");

            return Command::FAILURE;
        }
    }

    private function renderResolution(ThirdPartyResolution $res): int
    {
        $this->line('');
        if (! $res->timologiaPresent) {
            $this->warn('mod_timologia tables not present on this WHMCS — no third-party routing. Every line bills the WHMCS client.');
        }

        $rows = [];
        foreach ($res->lines as $line) {
            $contact = $line['contact'] ?? null;
            $rows[] = [
                $line['item_id'] ?? '?',
                $line['type'] ?? '',
                $line['service_type'] ?? '—',
                $this->truncate((string) ($line['description'] ?? ''), 40),
                ! empty($line['routed']) ? '→ τρίτο' : 'πελάτης',
                $contact
                    ? html_entity_decode((string) ($contact['company_name'] ?? ''), ENT_QUOTES | ENT_HTML5, 'UTF-8')
                        .' ('.($contact['gr_vatno'] ?? '').')'
                    : '—',
                ! empty($line['is_receipt']) ? 'απόδειξη' : 'τιμολόγιο',
            ];
        }

        $this->table(
            ['Item', 'Type', 'Service', 'Description', 'Bills', 'Contact (ΑΦΜ)', 'Doc'],
            $rows,
        );

        $parties = $res->distinctParties();
        $this->line('');
        $this->info(sprintf(
            'WHMCS invoice #%d (client %d): %d line(s), %d routed, %d distinct billing part%s.',
            $res->whmcsInvoiceId,
            $res->whmcsUserId,
            count($res->lines),
            count($res->routedLines()),
            $parties,
            $parties === 1 ? 'y' : 'ies',
        ));

        if ($res->isMultiParty()) {
            $this->warn('MULTI-PARTY: this invoice mixes billing parties → would be staged as "needs split" for the operator (T-1b).');
        } elseif ($res->singleContact() !== null) {
            $name = html_entity_decode((string) ($res->singleContact()['company_name'] ?? ''), ENT_QUOTES | ENT_HTML5, 'UTF-8');
            $this->info("Single third party: whole invoice would bill → {$name} (T-1b).");
        } else {
            $this->info('No third-party routing: would bill the WHMCS client as today.');
        }

        return Command::SUCCESS;
    }

    /**
     * @param  array<int, array{userid: int, routes: int}>  $resellers
     */
    private function renderResellers(array $resellers): int
    {
        $this->line('');
        if ($resellers === []) {
            $this->info('No WHMCS clients route any service to a third party.');

            return Command::SUCCESS;
        }

        $this->table(
            ['WHMCS client id', 'Routed services'],
            array_map(static fn ($r) => [$r['userid'], $r['routes']], $resellers),
        );
        $this->line('');
        $this->info(sprintf('%d client(s) route at least one service to a third party.', count($resellers)));
        $this->line('These map to ekdosi Customers via customers.whmcs_client_id (the reseller flag, #6).');

        return Command::SUCCESS;
    }

    private function truncate(string $s, int $len): string
    {
        return mb_strlen($s) > $len ? mb_substr($s, 0, $len - 1).'…' : $s;
    }
}
