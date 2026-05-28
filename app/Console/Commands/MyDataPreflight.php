<?php

namespace App\Console\Commands;

use App\Enums\MyDataMode;
use App\Models\Company;
use App\Models\InvoiceType;
use App\Models\VatCategory;
use App\Support\MyData\Codes;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;

/**
 * Pre-flight audit for myDATA filing. Read-only, no AADE calls — it
 * checks each tenant's CONFIGURATION against the AADE code tables
 * (App\Support\MyData\Codes, from the §8 appendix) so configuration
 * gaps are caught BEFORE AADE rejects a real submission.
 *
 * What it catches (and the AADE error each would otherwise cause):
 *   - invoice type with no / invalid mydata_type          → [223]/[204]
 *   - income type missing or invalid income classification → [230]
 *   - VAT rate that maps to no AADE category               → submit throw
 *   - 0% VAT category (needs vatExemptionCategory)         → [217]
 *   - 4% VAT rate (ambiguous: category 6 vs 10)            → wrong category
 *   - tenant provider/mode/credentials not ready
 *
 * Exit codes: 0 = clean, 1 = command error, 2 = issues found.
 *
 * Usage:
 *   php artisan mydata:preflight                # all gr-mydata tenants
 *   php artisan mydata:preflight --tenant=myip  # one tenant (any provider)
 */
class MyDataPreflight extends Command
{
    protected $signature = 'mydata:preflight {--tenant= : Company slug or id (default: all gr-mydata tenants)}';

    protected $description = 'Audit tenant invoice-type / VAT config against the AADE myDATA code tables (read-only).';

    private int $errorCount = 0;

    private int $warnCount = 0;

    public function handle(): int
    {
        $companies = $this->resolveCompanies();

        if ($companies === null) {
            return self::FAILURE;
        }

        if ($companies->isEmpty()) {
            $this->warn('No matching tenants. (Default scope is einvoice_provider=gr-mydata.)');

            return self::SUCCESS;
        }

        foreach ($companies as $company) {
            $this->auditCompany($company);
        }

        $this->newLine();
        $this->line(str_repeat('─', 50));
        if ($this->errorCount === 0 && $this->warnCount === 0) {
            $this->info('✓ Pre-flight clean — no configuration issues found.');

            return self::SUCCESS;
        }

        $this->line("Summary: {$this->errorCount} error(s), {$this->warnCount} warning(s).");

        return $this->errorCount > 0 ? 2 : self::SUCCESS;
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

    private function auditCompany(Company $company): void
    {
        $this->newLine();
        $this->line(str_repeat('═', 50));
        $this->line("Tenant: {$company->name} (#{$company->id})");

        // Tenant-level readiness.
        if ($company->einvoice_provider !== 'gr-mydata') {
            $this->flag('warn', "provider is '{$company->einvoice_provider}', not gr-mydata — myDATA filing won't run");
        }
        if ($company->mydata_mode_enum === MyDataMode::Off) {
            $this->flag('warn', 'mydata_mode is Off — no submissions will be attempted');
        }
        if (empty($company->mydata_aade_id) || empty($company->mydata_subscription_key)) {
            $this->flag('warn', 'myDATA credentials not set (mydata_aade_id / mydata_subscription_key)');
        }

        $this->auditInvoiceTypes($company);
        $this->auditVatCategories($company);
    }

    private function auditInvoiceTypes(Company $company): void
    {
        $types = InvoiceType::query()->where('company_id', $company->id)->orderBy('code')->get();

        $this->newLine();
        $this->line("Invoice types ({$types->count()}):");

        if ($types->isEmpty()) {
            $this->flag('warn', '  no invoice types configured');

            return;
        }

        foreach ($types as $type) {
            $label = "  [{$type->code}] {$type->name}";
            $issues = [];

            $mt = $type->mydata_type;
            if (empty($mt)) {
                // Not an error: a blank mydata_type means "this type is
                // never filed to myDATA" — legitimate for delivery /
                // aggregation / internal docs (ΣΔΕΠ, ΣΔΑΠ, etc.). Only a
                // problem if the operator expects it filed.
                $issues[] = 'WARN mydata_type not set — never filed to myDATA (OK for delivery/internal docs; a problem if you expect it filed)';
            } elseif (! Codes::invoiceTypeExists($mt)) {
                $issues[] = "ERROR mydata_type '{$mt}' is not a valid AADE invoice type ([223])";
            } elseif (Codes::isIncomeInvoiceType($mt)) {
                $cls = $type->mydata_income_class;
                $cat = $type->mydata_income_class_category;

                if (empty($cls)) {
                    $issues[] = 'ERROR income classification type missing ([230])';
                } elseif (! Codes::isValidIncomeClassType($cls)) {
                    $issues[] = "ERROR income class '{$cls}' is not a valid E3_* code (§8.9)";
                }

                if (empty($cat)) {
                    $issues[] = 'ERROR income classification category missing ([230])';
                } elseif (! Codes::isValidIncomeClassCategory($cat)) {
                    $issues[] = "ERROR income category '{$cat}' is not a valid code (§8.8)";
                }
            }

            $this->reportRow($label, $issues);
        }
    }

    private function auditVatCategories(Company $company): void
    {
        $vats = VatCategory::query()->where('company_id', $company->id)->orderBy('rate')->get();

        $this->newLine();
        $this->line("VAT categories ({$vats->count()}):");

        if ($vats->isEmpty()) {
            $this->flag('warn', '  no VAT categories configured');

            return;
        }

        foreach ($vats as $vat) {
            $rate = (float) $vat->rate;
            $label = '  '.rtrim(rtrim(number_format($rate, 2), '0'), '.').'% — '.($vat->description ?? '');
            $issues = [];

            $matches = Codes::vatCategoriesForRate($rate);

            if ($rate == 0.0) {
                // Maps to category 7, which AADE requires a
                // vatExemptionCategory for ([217]). The submitter
                // currently throws on 0% until exemption handling lands.
                $issues[] = 'WARN 0% → AADE category 7 needs a vatExemptionCategory ([217]); submitter throws on 0% today';
            } elseif (empty($matches)) {
                $issues[] = "ERROR rate {$rate}% maps to no AADE VAT category (§8.2)";
            } elseif (count($matches) > 1) {
                $list = implode('/', $matches);
                $issues[] = "WARN rate {$rate}% is ambiguous — AADE categories {$list}; confirm which regime applies";
            }

            $this->reportRow($label, $issues);
        }
    }

    /** @param list<string> $issues */
    private function reportRow(string $label, array $issues): void
    {
        if (empty($issues)) {
            $this->line("<fg=green>  ✓</> {$label}");

            return;
        }

        // Red ✗ only if there's a genuine ERROR; warning-only rows get ⚠.
        $hasError = collect($issues)->contains(fn ($i) => str_starts_with($i, 'ERROR'));
        $this->line($hasError ? "<fg=red>  ✗</> {$label}" : "<fg=yellow>  ⚠</> {$label}");
        foreach ($issues as $issue) {
            $isError = str_starts_with($issue, 'ERROR');
            $isError ? $this->errorCount++ : $this->warnCount++;
            $color = $isError ? 'red' : 'yellow';
            $this->line("      <fg={$color}>{$issue}</>");
        }
    }

    private function flag(string $level, string $msg): void
    {
        if ($level === 'warn') {
            $this->warnCount++;
            $this->line("  <fg=yellow>⚠ {$msg}</>");
        } else {
            $this->errorCount++;
            $this->line("  <fg=red>✗ {$msg}</>");
        }
    }
}
