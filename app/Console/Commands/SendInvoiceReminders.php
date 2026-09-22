<?php

namespace App\Console\Commands;

use App\Models\Company;
use App\Models\InvoiceReminder;
use App\Services\Reminders\ReminderRunner;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Throwable;

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

        // Enabled tenants — plus any switched off that still have reminders
        // waiting, so the run cancels those instead of leaving them sendable.
        $companies = Company::query()
            ->when(
                $this->option('tenant'),
                fn ($q, $slug) => $q->where('slug', $slug),
                fn ($q) => $q->where(fn ($w) => $w
                    ->where('reminders_enabled', true)
                    ->orWhereExists(fn ($e) => $e->selectRaw('1')->from('invoice_reminders')
                        ->whereColumn('invoice_reminders.company_id', 'companies.id')
                        ->whereIn('invoice_reminders.status', [InvoiceReminder::STATUS_AWAITING, InvoiceReminder::STATUS_QUEUED]))),
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

        $failed = false;
        foreach ($companies as $company) {
            // One tenant's failure must not cost the others their reminders.
            try {
                $r = $runner->run($company, $today, $dryRun);
            } catch (Throwable $e) {
                report($e);
                $this->error("[{$company->slug}] σφάλμα: {$e->getMessage()}");
                $failed = true;

                continue;
            }

            $this->info(match (true) {
                ! $company->reminders_enabled => "[{$company->slug}] οι υπενθυμίσεις είναι ανενεργές".($r['cancelled'] > 0 ? " · ακυρώθηκαν {$r['cancelled']} που περίμεναν." : '.'),
                $dryRun => "[{$company->slug}] θα καταγράφονταν {$r['planned']} υπενθυμίσεις (dry-run).",
                default => "[{$company->slug}] νέες υπενθυμίσεις: {$r['created']} · ακυρώθηκαν: {$r['cancelled']}.",
            });
        }

        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
