<?php

namespace App\Console\Commands;

use App\Models\Company;
use App\Services\Ergani\ErganiClient;
use App\Services\Ergani\ErganiRosterWatch;
use App\Services\Hr\LeaveWorkflow;
use Filament\Notifications\Notification;
use Illuminate\Console\Command;

/**
 * «Φύλακας κάρτας» — weekly, READ-ONLY: asks ΕΡΓΑΝΗ (production, EX_BASE_01)
 * whether each ΕΡΓΑΝΗ-enabled employer is now in the Ψηφιακή Κάρτα sector,
 * stores it on the company and rings the admins the day it flips to «yes»
 * (MyIP is not, 2026-09 — the sector list keeps growing). Declares nothing.
 */
class ErganiWatch extends Command
{
    protected $signature = 'ergani:watch {--tenant= : slug — one company only}';

    protected $description = 'ΕΡΓΑΝΗ read-only weekly watch: digital-card obligation (EX_BASE_01) + roster differences vs Εργαζόμενοι (EX_BASE_05) — production, declares nothing';

    public function handle(): int
    {
        $companies = Company::query()
            ->where('ergani_enabled', true)
            ->when($this->option('tenant'), fn ($q, $slug) => $q->where('slug', $slug))
            ->get()
            ->filter(fn (Company $c): bool => filled($c->ergani_username) && filled($c->ergani_password));

        $failed = 0;
        foreach ($companies as $company) {
            // Two independent checks, each isolated: one failing never skips the other
            // or the remaining tenants.
            try {
                $this->cardSector($company);
            } catch (\Throwable $e) {
                $this->warn("{$company->slug} (κάρτα): {$e->getMessage()}");
                report($e);
                $failed++;
            }
            try {
                $diff = app(ErganiRosterWatch::class)->check($company);
                $this->line("{$company->slug}: προσωπικό — ".(ErganiRosterWatch::count($diff) === 0 ? 'καμία διαφορά' : implode(' · ', ErganiRosterWatch::lines($diff))));
            } catch (\Throwable $e) {
                $this->warn("{$company->slug} (προσωπικό): {$e->getMessage()}");
                report($e);
                $failed++;
            }
        }

        // Any failed tenant → FAILURE, so ops:health shows it (the others were still checked).
        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }

    /** IsInCardSector (EX_BASE_01, production) → stored; bell the admins once on the flip. */
    private function cardSector(Company $company): void
    {
        $production = clone $company;
        $production->ergani_mode = 'production';   // in memory only — never saved
        $inSector = (new ErganiClient($production))->employerInfo()['in_card_sector'];
        if ($inSector === null) {
            throw new \RuntimeException('το ΕΡΓΑΝΗ δεν επέστρεψε IsInCardSector — δεν αποθηκεύτηκε τίποτα');
        }

        $was = $company->ergani_card_sector;
        $company->forceFill(['ergani_card_sector' => $inSector, 'ergani_card_sector_checked_at' => now()])->saveQuietly();
        $this->line("{$company->slug}: ".($inSector ? 'ΥΠΟΧΡΕΗ κάρτας' : 'όχι υπόχρεη κάρτας'));

        if ($inSector && $was !== true) {
            $recipients = LeaveWorkflow::usersWhoCan($company, 'Update:LeaveRequest');
            if ($recipients->isNotEmpty()) {
                Notification::make()
                    ->title('ΕΡΓΑΝΗ: η εταιρεία εντάχθηκε στην Ψηφιακή Κάρτα Εργασίας')
                    ->body('Το ΕΡΓΑΝΗ δείχνει πλέον «υπόχρεη κάρτας» ('.$company->name.'). Μιλήστε με τον λογιστή για την ημερομηνία έναρξης· '
                        .'το ekdosi έχει έτοιμη κάρτα + tablet «ρολόι» (Εταιρεία → ΕΡΓΑΝΗ → «Αυτόματη δήλωση ψηφιακής κάρτας»).')
                    ->icon('heroicon-o-finger-print')
                    ->warning()
                    ->sendToDatabase($recipients);
            }
        }
    }
}
