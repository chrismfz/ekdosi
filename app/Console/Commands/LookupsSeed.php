<?php

namespace App\Console\Commands;

use App\Models\Company;
use App\Services\MyData\MyDataLookupSeeder;
use App\Support\Tenancy\CompanyContext;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;

/**
 * (Re-)seed the standard Greek AADE lookup tables for a tenant — the CLI twin of
 * the per-resource «Εισαγωγή τυπικών» panel action and the install-time seed. It
 * runs {@see MyDataLookupSeeder::seedStandardLookups}, which is idempotent, so it
 * is safe to run anytime: it CREATES whatever standard rows a tenant is missing
 * (a newly-shipped invoice type, a payment method, a unit…) and FILL-EMPTIES the
 * missing myDATA classification on pre-existing invoice types — never touching an
 * operator's edits. (Product categories are new-rows-only: an EXISTING category is
 * never reclassified, to avoid a silent §8.6 change to already-filed goods.)
 *
 * The headless top-up path for a shared-hosting/cPanel deploy: after `git pull`,
 * `php artisan lookups:seed --all` brings every tenant up to the current seed set
 * without clicking through the panel per tenant.
 *
 * Scope: Greek (country=GR) tenants — the seed is Greek AADE data (VAT rates §8.2,
 * invoice types §8.1). A non-GR tenant is skipped unless named explicitly.
 *
 *   php artisan lookups:seed --tenant=myip       # one tenant
 *   php artisan lookups:seed --all               # every GR tenant
 *   php artisan lookups:seed --all --json        # machine-readable
 */
class LookupsSeed extends Command
{
    protected $signature = 'lookups:seed
        {--tenant= : Company slug or id (a single tenant; runs even if non-GR)}
        {--all : Run for every GR tenant}
        {--json : Machine-readable output}';

    protected $description = 'Top up a tenant\'s standard Greek AADE lookups (VAT / invoice types / payment methods / units / product-category income class). Idempotent + fill-empty.';

    public function handle(MyDataLookupSeeder $seeder, CompanyContext $context): int
    {
        $companies = $this->resolveCompanies();
        if ($companies === null) {
            return self::FAILURE;
        }
        if ($companies->isEmpty()) {
            $this->warn('Κανένας tenant προς seed. Δώσε --tenant=SLUG ή --all.');

            return self::SUCCESS;
        }

        $results = [];
        foreach ($companies as $company) {
            // Seed under the tenant's own context so an ambient scope (if any) agrees
            // with the seeder's explicit company_id — same guard as CreateCompany.
            $r = $context->actAs($company, fn (): array => $seeder->seedStandardLookups($company));
            $results[] = ['slug' => $company->slug, 'name' => $company->name, 'seeded' => $r];
        }

        if ($this->option('json')) {
            $this->line((string) json_encode($results, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

            return self::SUCCESS;
        }

        foreach ($results as $row) {
            $this->newLine();
            $this->line("<options=bold>{$row['name']}</> <fg=gray>({$row['slug']})</>");
            foreach ($this->rows($row['seeded']) as [$label, $counts]) {
                $this->components->twoColumnDetail('  '.$label, $counts);
            }
        }
        $this->newLine();
        $this->info('✓ Ολοκληρώθηκε (idempotent — τα υπάρχοντα δεν πειράχτηκαν).');

        return self::SUCCESS;
    }

    /**
     * @param  array<string, array{created:int, skipped:int, filled?:int}>  $seeded
     * @return list<array{0:string, 1:string}>
     */
    private function rows(array $seeded): array
    {
        $labels = [
            'vat' => 'Κατηγορίες ΦΠΑ',
            'types' => 'Τύποι παραστατικών',
            'payment_methods' => 'Τρόποι πληρωμής',
            'distribution_aims' => 'Σκοποί διακίνησης',
            'metric_units' => 'Μονάδες μέτρησης',
            'delivery_methods' => 'Τρόποι αποστολής',
            'product_categories' => 'Κατηγορίες προϊόντων',
        ];

        $out = [];
        foreach ($labels as $key => $label) {
            $c = $seeded[$key] ?? [];
            $parts = ['νέα '.($c['created'] ?? 0)];
            if (isset($c['filled'])) {
                $parts[] = 'συμπληρώθηκαν '.$c['filled'];
            }
            $parts[] = 'υπήρχαν '.($c['skipped'] ?? 0);
            $out[] = [$label, implode(' · ', $parts)];
        }

        return $out;
    }

    /** @return Collection<int, Company>|null */
    private function resolveCompanies(): ?Collection
    {
        $arg = $this->option('tenant');

        if ($arg) {
            $company = Company::findBySlugOrId((string) $arg);
            if ($company === null) {
                $this->error("Tenant '{$arg}' not found.");

                return null;
            }

            return collect([$company]);
        }

        if (! $this->option('all')) {
            $this->error('Δώσε --tenant=SLUG για έναν tenant ή --all για όλους τους GR tenants.');

            return null;
        }

        return Company::query()->where('country_code', 'GR')->orderBy('slug')->get();
    }
}
