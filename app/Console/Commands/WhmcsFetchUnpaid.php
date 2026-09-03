<?php

namespace App\Console\Commands;

use App\Exceptions\Whmcs\WhmcsApiException;
use App\Exceptions\Whmcs\WhmcsAuthenticationFailed;
use App\Exceptions\Whmcs\WhmcsNotConfigured;
use App\Exceptions\Whmcs\WhmcsUnreachable;
use App\Models\Company;
use App\Services\Whmcs\WhmcsUnpaidFetcher;
use Illuminate\Console\Command;

/**
 * WHMCS bridge: stage the UNPAID invoices of «τιμολόγιο-πριν-την-πληρωμή» customers
 * (`customers.needs_invoice_before_payment`) into the inbox for MANUAL επί-πιστώσει
 * issuance — the public-sector / Α.Ε. «θέλω τιμολόγιο πριν πληρώσω» case.
 *
 *   php artisan whmcs:fetch-unpaid --tenant=myip
 *
 * NEVER files at AADE and NEVER auto-issues: it only stages, the rows are `Unpaid`
 * (held by the auto-issuer), and issuance is the operator's manual «Δημιουργία
 * Παραστατικού». Idempotent across re-runs (upsert key = company_id, whmcs_invoice_id);
 * once a customer pays, the paid row arrives via `whmcs:fetch-pending` and refreshes
 * the same inbox row to «Πληρωμένο».
 *
 * Exit codes mirror whmcs:fetch-pending so scheduler wrappers behave the same:
 *   0 success · 1 generic WHMCS error · 2 invalid usage · 3 WHMCS not configured ·
 *   4 auth failed · 5 unreachable · 6 unknown tenant slug ·
 *   7 partial (≥1 client listing / invoice failed to stage).
 */
class WhmcsFetchUnpaid extends Command
{
    protected $signature = 'whmcs:fetch-unpaid {--tenant= : Company slug. Required.}';

    protected $description = 'WHMCS bridge: stage UNPAID invoices of «τιμολόγιο-πριν-την-πληρωμή» customers into the inbox for manual επί-πιστώσει issuance. Never files, never auto-issues.';

    public function handle(WhmcsUnpaidFetcher $fetcher): int
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

        $this->info("Tenant: {$tenant->name} (slug={$tenant->slug})");

        try {
            $summary = $fetcher->fetch($tenant);
        } catch (WhmcsNotConfigured $e) {
            $this->error($e->getMessage());

            return 3;
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

        $this->line('');
        $this->info(sprintf(
            'Πελάτες «τιμολόγιο πριν την πληρωμή»: %d · απλήρωτα που βρέθηκαν: %d · staged: %d · refreshed: %d · failed: %d.',
            $summary->customers,
            $summary->unpaidSeen,
            $summary->created,
            $summary->updated,
            $summary->failed,
        ));

        if ($summary->skippedNoLink > 0) {
            $this->warn(sprintf(
                '%d πελάτης/ες με σήμανση «τιμολόγιο πριν την πληρωμή» ΔΕΝ έχουν σύνδεση WHMCS (whmcs_client_id) — '
                .'σύνδεσέ τους για να έρθουν τα απλήρωτά τους.',
                $summary->skippedNoLink,
            ));
        }

        if ($summary->customers === 0 && $summary->skippedNoLink === 0) {
            $this->line('Κανένας πελάτης με σήμανση «τιμολόγιο πριν την πληρωμή». Τίποτα να κάνω.');
        }

        return $summary->failed > 0 ? 7 : Command::SUCCESS;
    }
}
