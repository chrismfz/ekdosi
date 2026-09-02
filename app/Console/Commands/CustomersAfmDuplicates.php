<?php

namespace App\Console\Commands;

use App\Models\Company;
use App\Models\Customer;
use App\Services\Customers\CustomerAfmDuplicates;
use Illuminate\Console\Command;

/**
 * Lists customers that share an ΑΦΜ inside a tenant — the pre-flight for the
 * UNIQUE(company_id, afm_key) constraint (the migration refuses while any
 * exist) and a standing data-quality check afterwards.
 *
 *   php artisan customers:afm-duplicates [--tenant=SLUG]
 *
 * Exit 0 = none, 1 = duplicates found (read-only, never changes data). To
 * resolve: correct the wrong ΑΦΜ on one of them, or move its documents and
 * delete it — the operator decides which row is the real party.
 */
class CustomersAfmDuplicates extends Command
{
    protected $signature = 'customers:afm-duplicates {--tenant= : Company slug (default: all tenants)}';

    protected $description = 'Πελάτες με το ίδιο ΑΦΜ μέσα στην ίδια εταιρεία (read-only έλεγχος)';

    public function handle(CustomerAfmDuplicates $duplicates): int
    {
        $companyId = null;
        if ($slug = $this->option('tenant')) {
            $company = Company::query()->where('slug', $slug)->first();
            if ($company === null) {
                $this->error("Άγνωστη εταιρεία: {$slug}");

                return self::INVALID;
            }
            $companyId = (int) $company->id;
        }

        $groups = $duplicates->find($companyId);

        if ($groups->isEmpty()) {
            $this->info('Κανένας διπλός ΑΦΜ.');

            return self::SUCCESS;
        }

        $names = Company::query()->whereIn('id', $groups->pluck('company_id')->unique())->pluck('name', 'id');

        $rows = [];
        foreach ($groups as $g) {
            foreach ($g['customers'] as $c) {
                /** @var Customer $c */
                $rows[] = [
                    $names[$g['company_id']] ?? $g['company_id'],
                    $g['afm_key'],
                    $c->id,
                    $c->name,
                    $c->afm,
                    $c->trashed() ? 'ΔΙΑΓΡΑΜΜΕΝΟΣ' : '',
                ];
            }
        }

        $this->table(['Εταιρεία', 'ΑΦΜ (κλειδί)', '#', 'Επωνυμία', 'ΑΦΜ όπως είναι', ''], $rows);
        $this->warn($groups->count().' ομάδες διπλών ΑΦΜ. Διόρθωσε το ΑΦΜ του λάθος πελάτη ή μετέφερε τα παραστατικά του και διέγραψέ τον — μετά ξανατρέξε migrate.');

        return self::FAILURE;
    }
}
