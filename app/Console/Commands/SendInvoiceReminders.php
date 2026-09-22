<?php

namespace App\Console\Commands;

use App\Models\Company;
use App\Services\Reminders\ReminderRunner;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

/**
 * Daily payment reminders for every tenant that switched them on (or one named
 * tenant). Review mode records them «προς έγκριση» and rings the operators'
 * bell; auto mode queues the emails. --dry-run only reports what would happen.
 */
class SendInvoiceReminders extends Command
{
    protected $signature = 'invoices:send-reminders {--tenant= : Limit to one company slug} {--dry-run : Report only, record and send nothing}';

    protected $description = 'Υπενθυμίσεις πληρωμής (στυλ WHMCS) για ανεξόφλητα τιμολόγια και προτιμολόγια.';

    public function handle(ReminderRunner $runner): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $today = CarbonImmutable::today();

        $companies = Company::query()
            ->when(
                $this->option('tenant'),
                fn ($q, $slug) => $q->where('slug', $slug),
                fn ($q) => $q->where('reminders_enabled', true),
            )
            ->get();

        if ($companies->isEmpty()) {
            if ($this->option('tenant')) {
                $this->warn('Δεν βρέθηκε tenant.');

                return self::FAILURE;
            }
            $this->info('Καμία εταιρεία με ενεργές υπενθυμίσεις.');

            return self::SUCCESS;
        }

        foreach ($companies as $company) {
            if (! $company->reminders_enabled) {
                $this->line("[{$company->slug}] οι υπενθυμίσεις είναι ανενεργές.");

                continue;
            }

            $r = $runner->run($company, $today, $dryRun);
            $this->info($dryRun
                ? "[{$company->slug}] θα καταγράφονταν {$r['planned']} υπενθυμίσεις (dry-run)."
                : "[{$company->slug}] νέες υπενθυμίσεις: {$r['created']} · ακυρώθηκαν: {$r['cancelled']}.");
        }

        return self::SUCCESS;
    }
}
