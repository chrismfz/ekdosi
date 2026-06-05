<?php

namespace App\Console\Commands;

use App\Models\Company;
use App\Services\Portability\CompanyDataWiper;
use Illuminate\Console\Command;

/**
 * Wipe a tenant's transactional data (keep company + settings + setup) — the
 * clean slate before a Firebird re-import. Dry-run by default; --execute applies.
 *
 *   php artisan company:wipe --tenant=myip                       # preview
 *   php artisan company:wipe --tenant=myip --execute --force     # apply
 *   php artisan company:wipe --tenant=myip --keep-parties --execute
 */
class CompanyWipe extends Command
{
    protected $signature = 'company:wipe
        {--tenant= : Company slug}
        {--keep-parties : Keep customers/suppliers/products}
        {--reset-counter : Roll each invoice type ΑΑ counter back to 1}
        {--execute : Apply (default is a dry-run preview)}
        {--force : Allow wiping even when invoices are filed at AADE (VALID)}';

    protected $description = 'Wipe a company\'s transactional data, keeping settings + setup (dry-run by default)';

    public function handle(CompanyDataWiper $wiper): int
    {
        $slug = (string) $this->option('tenant');
        $company = $slug === '' ? null : Company::query()->where('slug', $slug)->first();
        if ($company === null) {
            $this->error('Δώσε υπαρκτό --tenant=SLUG.');

            return self::FAILURE;
        }

        $keepParties = (bool) $this->option('keep-parties');
        $execute = (bool) $this->option('execute');

        $plan = $wiper->plan($company, $keepParties);
        $filed = $wiper->filedAtAadeCount($company);

        $this->line(($execute ? 'ΔΙΑΓΡΑΦΗ' : 'ΠΡΟΕΠΙΣΚΟΠΗΣΗ (dry-run)').' — «'.$company->slug.'»'
            .($keepParties ? ' (κρατά πελάτες/προμηθευτές/προϊόντα)' : ''));
        foreach ($plan as $table => $count) {
            $this->line(sprintf('  %-24s %d', $table, $count));
        }
        if ($plan === []) {
            $this->info('Δεν υπάρχουν συναλλακτικά δεδομένα για διαγραφή.');

            return self::SUCCESS;
        }

        if ($filed > 0) {
            $this->warn("⚠ {$filed} παραστατικά είναι ΥΠΟΒΛΗΜΕΝΑ στην ΑΑΔΕ (VALID) — η τοπική διαγραφή ΔΕΝ τα ακυρώνει εκεί.");
            if ($execute && ! $this->option('force')) {
                $this->error('Διακοπή: χρειάζεται --force για διαγραφή ενώ υπάρχουν υποβλημένα στην ΑΑΔΕ.');

                return self::FAILURE;
            }
        }

        if (! $execute) {
            $this->warn('Dry-run: τίποτα δεν διαγράφηκε. Ξανατρέξε με --execute (+ --force αν χρειάζεται).');

            return self::SUCCESS;
        }

        $deleted = $wiper->wipe($company, $keepParties, (bool) $this->option('reset-counter'));
        $this->info('Διαγράφηκαν '.array_sum($deleted).' γραμμές σε '.count($deleted).' πίνακες.'
            .($this->option('reset-counter') ? ' Ο counter μηδενίστηκε.' : ''));

        return self::SUCCESS;
    }
}
