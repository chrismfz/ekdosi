<?php

namespace App\Console\Commands;

use App\Models\Company;
use App\Models\Customer;
use App\Services\Customers\CustomerAfmDuplicates;
use App\Services\Customers\MergeCustomers;
use App\Services\Customers\MergeCustomersResult;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Lists customers that share an ΑΦΜ inside a tenant — the pre-flight for the
 * UNIQUE(company_id, afm_key) constraint (the migration refuses while any
 * exist) and a standing data-quality check afterwards.
 *
 *   php artisan customers:afm-duplicates [--tenant=SLUG]
 *
 * Exit 0 = none, 1 = duplicates found (read-only, never changes data). The
 * table shows HOW MUCH hangs off each row (παραστατικά/πληρωμές/…) and marks
 * the one `customers:merge` would keep, so the operator can decide without
 * opening the database. To resolve: `php artisan customers:merge <keep> <drop>`
 * (or simply correct the wrong ΑΦΜ, when they are NOT the same party).
 */
class CustomersAfmDuplicates extends Command
{
    protected $signature = 'customers:afm-duplicates {--tenant= : Company slug (default: all tenants)}';

    protected $description = 'Πελάτες με το ίδιο ΑΦΜ μέσα στην ίδια εταιρεία (read-only έλεγχος)';

    /**
     * The id `customers:merge` would keep — the same rule as
     * MergeCustomers::suggestKeeper (fullest row, tie → smaller id).
     *
     * @param  iterable<Customer>  $customers
     */
    private function suggestedKeeper(iterable $customers): int
    {
        // A trashed row can never survive a merge (MergeCustomers refuses it),
        // so it must never carry the ✓ — else the printed command dead-ends.
        $candidates = collect($customers);
        $live = $candidates->reject(fn (Customer $c): bool => $c->trashed());
        if ($live->isNotEmpty()) {
            $candidates = $live;
        }

        $best = null;
        $bestCount = -1;
        foreach ($candidates as $c) {
            $count = $this->attachedCount($c);
            if ($count > $bestCount || ($count === $bestCount && (int) $c->id < $best)) {
                $best = (int) $c->id;
                $bestCount = $count;
            }
        }

        return (int) $best;
    }

    /** Total rows pointing at this customer (the merge service's own map). */
    private function attachedCount(Customer $customer): int
    {
        $total = 0;
        foreach ($this->attachedBreakdown($customer) as $count) {
            $total += $count;
        }

        return $total;
    }

    /** @var array<int, array<string, int>> memo: the report asks twice per row */
    private array $breakdowns = [];

    /** @return array<string, int> table => rows, non-empty ones only */
    private function attachedBreakdown(Customer $customer): array
    {
        if (isset($this->breakdowns[$customer->id])) {
            return $this->breakdowns[$customer->id];
        }

        $out = [];
        foreach (MergeCustomers::FOREIGN_KEYS as $table => $column) {
            if (! Schema::hasTable($table) || ! Schema::hasColumn($table, $column)) {
                continue;
            }
            $query = DB::table($table)->where($column, $customer->id);
            if (Schema::hasColumn($table, 'company_id')) {
                $query->where('company_id', $customer->company_id);
            }
            $count = $query->count();
            if ($count > 0) {
                $out[$table] = $count;
            }
        }

        return $this->breakdowns[$customer->id] = $out;
    }

    /** «3 παραστατικά, 1 πληρωμές» — what the operator weighs. */
    private function hangingOff(Customer $customer): string
    {
        $parts = [];
        foreach ($this->attachedBreakdown($customer) as $table => $count) {
            $parts[] = $count.' '.MergeCustomersResult::label($table);
        }

        return $parts === [] ? '—' : implode(', ', $parts);
    }

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
        $parked = $duplicates->findParked($companyId);

        $names = Company::query()
            ->whereIn('id', $groups->pluck('company_id')->merge($parked->pluck('company_id'))->unique())
            ->pluck('name', 'id');

        if ($groups->isEmpty()) {
            $this->info('Κανένας διπλός ΑΦΜ.');
            $this->reportParked($parked, $names);

            return self::SUCCESS;
        }

        $rows = [];
        $hints = [];
        foreach ($groups as $g) {
            // Which row `customers:merge` would keep by default (the fullest;
            // a tie goes to the smaller id) — shown as a ✓ so the operator sees
            // the recommendation without running anything.
            $suggested = $this->suggestedKeeper($g['customers']);

            foreach ($g['customers'] as $c) {
                /** @var Customer $c */
                $rows[] = [
                    $names[$g['company_id']] ?? $g['company_id'],
                    $g['afm_key'],
                    ((int) $c->id === $suggested ? '✓ ' : '  ').$c->id,
                    $c->name,
                    $c->afm,
                    $this->hangingOff($c),
                    $c->trashed() ? 'ΔΙΑΓΡΑΜΜΕΝΟΣ' : '',
                ];
            }

            // A merge needs a LIVE survivor: an all-trashed group is a restore
            // job first, so print that instead of a command that gets refused.
            if (collect($g['customers'])->every(fn (Customer $c): bool => $c->trashed())) {
                $hints[] = "  (ΑΦΜ {$g['afm_key']}: όλοι διαγραμμένοι — επανάφερε αυτόν που κρατάς και ξανατρέξε)";

                continue;
            }

            $others = collect($g['customers'])->reject(fn (Customer $c): bool => (int) $c->id === $suggested);
            foreach ($others as $other) {
                $hints[] = "  php artisan customers:merge {$suggested} {$other->id} --dry-run";
            }
        }

        $this->table(['Εταιρεία', 'ΑΦΜ (κλειδί)', '# (✓ = κρατάμε)', 'Επωνυμία', 'ΑΦΜ όπως είναι', 'Κρέμονται', ''], $rows);
        $this->warn($groups->count().' ομάδες διπλών ΑΦΜ. Αν είναι το ίδιο πρόσωπο, συγχώνευσέ τους· αλλιώς διόρθωσε το λάθος ΑΦΜ.');
        $this->line('Δες πρώτα τι θα μεταφερθεί:');
        foreach (array_unique($hints) as $hint) {
            $this->line($hint);
        }

        $this->reportParked($parked, $names);

        return self::FAILURE;
    }

    /**
     * Customers that carry a real ΑΦΜ but hold NO identity — the legacy
     * υποκατάστημα twins the ETL parked with `--afm-keep`. The UNIQUE index
     * permits them, so they are NOT a failure; they are listed so a deliberate
     * park can never quietly become forgotten state.
     *
     * @param  Collection<int, array{company_id:int, afm_key:string, holder:?Customer, parked:Collection<int, Customer>}>  $parked
     * @param  Collection<int, string>  $names
     */
    private function reportParked(Collection $parked, Collection $names): void
    {
        if ($parked->isEmpty()) {
            return;
        }

        $rows = [];
        foreach ($parked as $g) {
            $holder = $g['holder'];
            foreach ($g['parked'] as $c) {
                /** @var Customer $c */
                $rows[] = [
                    $names[$g['company_id']] ?? $g['company_id'],
                    $g['afm_key'],
                    $c->id.' '.$c->name.($c->trashed() ? ' [ΔΙΑΓΡΑΜΜΕΝΟΣ]' : ''),
                    $holder !== null ? $holder->id.' '.$holder->name : '— (κανείς)',
                    $this->hangingOff($c),
                ];
            }
        }

        $this->newLine();
        $this->warn(count($rows).' πελάτης/ες με ΑΦΜ αλλά ΧΩΡΙΣ ταυτότητα ΑΦΜ (parked από το ETL — --afm-keep):');
        $this->table(['Εταιρεία', 'ΑΦΜ', 'Χωρίς ταυτότητα', 'Την κρατά', 'Κρέμονται'], $rows);
        $this->line('  Κρατούν ΑΦΜ, παραστατικά και ιστορικό — απλώς δεν κρατούν την ταυτότητα ΑΦΜ. Δύο σωστές καταλήξεις:');
        $this->line('   • ίδιο πρόσωπο → php artisan customers:merge <την κρατά> <χωρίς ταυτότητα> --dry-run');
        $this->line('   • υποκατάστημα → κράτα ΕΝΑΝ πελάτη (την έδρα) και δήλωσε «Εγκατάσταση πελάτη (myDATA)» στο παραστατικό·');
        $this->line('     τον παλιό τον αφήνεις ανενεργό ως αρχείο (τα ήδη υποβεβλημένα παραστατικά ΔΕΝ πειράζονται).');
    }
}
