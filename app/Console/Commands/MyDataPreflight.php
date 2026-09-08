<?php

namespace App\Console\Commands;

use App\Models\Company;
use App\Services\MyData\ConfigAuditResult;
use App\Services\MyData\ConfigAuditRow;
use App\Services\MyData\MyDataConfigAudit;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;

/**
 * Pre-flight audit for myDATA filing. Read-only, no AADE calls — a thin renderer
 * over App\Services\MyData\MyDataConfigAudit (the SAME audit the «Έλεγχος
 * ρυθμίσεων» console tab and the Invoice Types readiness badge use), so a config
 * gap is caught BEFORE AADE rejects a real submission.
 *
 * Exit codes: 0 = clean (or warnings only), 1 = command error, 2 = errors found.
 *
 * Usage:
 *   php artisan mydata:preflight                # all gr-mydata tenants
 *   php artisan mydata:preflight --tenant=myip  # one tenant (any provider)
 */
class MyDataPreflight extends Command
{
    protected $signature = 'mydata:preflight {--tenant= : Company slug or id (default: all gr-mydata tenants)}';

    protected $description = 'Audit tenant invoice-type / VAT config against the AADE myDATA code tables (read-only).';

    public function handle(MyDataConfigAudit $audit): int
    {
        $companies = $this->resolveCompanies();

        if ($companies === null) {
            return self::FAILURE;
        }

        if ($companies->isEmpty()) {
            $this->warn('No matching tenants. (Default scope is einvoice_provider=gr-mydata.)');

            return self::SUCCESS;
        }

        $errors = 0;
        $warns = 0;

        foreach ($companies as $company) {
            $result = $audit->audit($company);
            $this->render($result);
            $errors += $result->errorCount();
            $warns += $result->warnCount();
        }

        $this->newLine();
        $this->line(str_repeat('─', 50));
        if ($errors === 0 && $warns === 0) {
            $this->info('✓ Pre-flight clean — no configuration issues found.');

            return self::SUCCESS;
        }

        $this->line("Summary: {$errors} error(s), {$warns} warning(s).");

        return $errors > 0 ? 2 : self::SUCCESS;
    }

    private function render(ConfigAuditResult $result): void
    {
        $this->newLine();
        $this->line(str_repeat('═', 50));
        $this->line("Tenant: {$result->company->name} (#{$result->company->id})");

        foreach ($result->tenant->findings as $f) {
            $this->line('  <fg=yellow>⚠ '.$f->message.'</>');
        }

        $this->newLine();
        $this->line('Invoice types ('.count($result->invoiceTypes).'):');
        foreach ($result->invoiceTypes as $row) {
            $this->renderRow($row);
        }

        $this->newLine();
        $this->line('VAT categories ('.count($result->vatCategories).'):');
        foreach ($result->vatCategories as $row) {
            $this->renderRow($row);
        }
    }

    private function renderRow(ConfigAuditRow $row): void
    {
        $mark = match ($row->status()) {
            'error' => '<fg=red>  ✗</>',
            'warn' => '<fg=yellow>  ⚠</>',
            default => '<fg=green>  ✓</>',
        };
        $this->line("{$mark}   {$row->label}");

        foreach ($row->findings as $f) {
            $color = $f->isError() ? 'red' : 'yellow';
            $prefix = $f->aadeCode ? "[{$f->aadeCode}] " : '';
            $this->line("      <fg={$color}>{$prefix}{$f->message}</>");
        }
    }

    /** @return Collection<int, Company>|null */
    private function resolveCompanies()
    {
        $arg = $this->option('tenant');

        if ($arg) {
            $company = Company::query()
                ->where(fn ($q) => $q
                    ->where('slug', $arg)
                    ->orWhere('id', is_numeric($arg) ? (int) $arg : 0))
                ->first();

            if (! $company) {
                $this->error("Tenant '{$arg}' not found.");

                return null;
            }

            return collect([$company]);
        }

        return Company::query()->where('einvoice_provider', 'gr-mydata')->get();
    }
}
