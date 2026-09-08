<?php

namespace App\Console\Commands;

use App\Models\Company;
use App\Services\MyDataSubmitter;
use Illuminate\Console\Command;
use Throwable;

/**
 * Configure a tenant's myDATA credentials from the CLI — the quick way
 * to set up a SANDBOX tenant for the Phase 2 live-reconciliation test
 * without tinker or clicking through the Filament Company form.
 *
 * The subscription key is read as HIDDEN input (->secret()) so it never
 * lands in shell history or the process list. Passing --key is supported
 * for scripted use but warns about the leak.
 *
 * Usage:
 *   php artisan mydata:set-credentials --tenant=myip            # prompts for aade-id + key (hidden)
 *   php artisan mydata:set-credentials --tenant=myip --test     # set, then verify against AADE
 *   php artisan mydata:set-credentials --tenant=myip --mode=production   # (be deliberate)
 */
class MyDataSetCredentials extends Command
{
    protected $signature = 'mydata:set-credentials
        {--tenant= : Company slug (or numeric id)}
        {--aade-id= : AADE user id (else prompted)}
        {--key= : Subscription key (NOT recommended — leaks to shell history; prefer the hidden prompt)}
        {--mode=sandbox : myDATA mode: sandbox (dev endpoint) or production}
        {--test : After saving, call AADE to verify the credentials}';

    protected $description = 'Set a tenant\'s myDATA credentials (sandbox by default) and optionally test the connection.';

    public function handle(): int
    {
        $tenantArg = $this->option('tenant');
        if (! $tenantArg) {
            $this->error('--tenant is required (company slug or id).');

            return self::FAILURE;
        }

        $tenant = Company::query()
            ->where(fn ($q) => $q
                ->where('slug', $tenantArg)
                ->orWhere('id', is_numeric($tenantArg) ? (int) $tenantArg : 0))
            ->first();

        if (! $tenant) {
            $this->error("Tenant '{$tenantArg}' not found.");

            return self::FAILURE;
        }

        $mode = $this->option('mode');
        if (! in_array($mode, ['sandbox', 'production'], true)) {
            $this->error("--mode must be 'sandbox' or 'production'.");

            return self::FAILURE;
        }

        $aadeId = $this->option('aade-id') ?: $this->ask('AADE user id');

        $key = $this->option('key');
        if ($key) {
            $this->warn('Passing --key leaks the secret to shell history. Prefer the hidden prompt next time.');
        } else {
            $key = $this->secret('Subscription key (hidden)');
        }

        if (empty($aadeId) || empty($key)) {
            $this->error('Both AADE user id and subscription key are required.');

            return self::FAILURE;
        }

        if ($mode === 'production' && ! $this->confirm('Set PRODUCTION (live filing) credentials for this tenant?', false)) {
            $this->line('Aborted.');

            return self::FAILURE;
        }

        // Direct attribute set (bypasses mass-assignment guard); the
        // subscription key columns are encrypted via the Company cast.
        // Credentials land in the slot matching the chosen mode so the
        // other environment's stored creds are left untouched.
        $tenant->einvoice_provider = 'gr-mydata';
        $tenant->mydata_mode = $mode;
        if ($mode === 'production') {
            $tenant->mydata_aade_id_production = $aadeId;
            $tenant->mydata_subscription_key_production = $key;
        } else {
            $tenant->mydata_aade_id_sandbox = $aadeId;
            $tenant->mydata_subscription_key_sandbox = $key;
        }
        $tenant->save();

        $this->info("Saved: {$tenant->name} (#{$tenant->id}) → provider=gr-mydata, mode={$mode}, aade_id={$aadeId}.");

        if (! $this->option('test')) {
            return self::SUCCESS;
        }

        $this->line('Testing connection to AADE…');
        try {
            $ok = (new MyDataSubmitter($tenant->fresh()))->testConnection();
        } catch (Throwable $e) {
            $this->error('Connection test could not complete (transport/parse): '.$e->getMessage());

            return self::FAILURE;
        }

        if ($ok) {
            $this->info('✓ Credentials accepted by AADE ('.$mode.' endpoint).');

            return self::SUCCESS;
        }

        $this->error('✗ AADE rejected the credentials (authentication failed).');

        return self::FAILURE;
    }
}
