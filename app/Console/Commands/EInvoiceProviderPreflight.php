<?php

namespace App\Console\Commands;

use App\Models\Company;
use App\Services\EInvoice\ProviderPreflight;
use Illuminate\Console\Command;

/**
 * Read-only readiness audit for ΥΠΑΗΕΣ provider tenants (P4 — the «Πάροχος Console»
 * in CLI form). No network calls. Exit 0 = ready, 2 = a `fail` check found.
 *
 *   php artisan einvoice:preflight                 # all gr-provider tenants
 *   php artisan einvoice:preflight --tenant=nexon  # one tenant
 */
class EInvoiceProviderPreflight extends Command
{
    protected $signature = 'einvoice:preflight {--tenant= : Company slug or id (default: all gr-provider tenants)}';

    protected $description = 'Audit a tenant\'s e-invoice provider configuration (read-only).';

    public function handle(ProviderPreflight $preflight): int
    {
        $tenant = $this->option('tenant');

        $companies = $tenant
            ? Company::query()
                ->where(fn ($q) => $q->where('slug', $tenant)->orWhere('id', is_numeric($tenant) ? (int) $tenant : 0))
                ->get()
            : Company::query()->where('einvoice_provider', 'gr-provider')->get();

        if ($tenant && $companies->isEmpty()) {
            $this->error("Tenant '{$tenant}' not found.");

            return self::FAILURE;
        }
        if ($companies->isEmpty()) {
            $this->warn('No provider tenants (einvoice_provider=gr-provider).');

            return self::SUCCESS;
        }

        $hasFail = false;
        foreach ($companies as $company) {
            $this->newLine();
            $this->line(str_repeat('═', 56));
            $this->line("Tenant: {$company->name} (#{$company->id})");

            foreach ($preflight->audit($company) as $check) {
                [$icon, $method] = match ($check['status']) {
                    'ok' => ['✓', 'info'],
                    'warn' => ['•', 'comment'],
                    default => ['✗', 'error'],
                };
                if ($check['status'] === 'fail') {
                    $hasFail = true;
                }
                $this->{$method}("  {$icon} {$check['label']}: {$check['detail']}");
            }
        }

        $this->newLine();

        return $hasFail ? 2 : self::SUCCESS;
    }
}
