<?php

namespace App\Console\Commands;

use App\Models\Company;
use App\Services\Ergani\ErganiClient;
use App\Services\Hr\LeaveWorkflow;
use Filament\Notifications\Notification;
use Illuminate\Console\Command;
use Illuminate\Http\Client\ConnectionException;

/**
 * «Φύλακας κάρτας» — weekly, READ-ONLY: asks ΕΡΓΑΝΗ (production, EX_BASE_01)
 * whether each ΕΡΓΑΝΗ-enabled employer is now in the Ψηφιακή Κάρτα sector,
 * stores it on the company and rings the admins the day it flips to «yes»
 * (MyIP is not, 2026-09 — the sector list keeps growing). Declares nothing.
 */
class ErganiWatch extends Command
{
    protected $signature = 'ergani:watch {--tenant= : slug — one company only}';

    protected $description = 'ΕΡΓΑΝΗ read-only watch: is the employer now obligated to the digital work card? (EX_BASE_01, production)';

    public function handle(): int
    {
        $companies = Company::query()
            ->where('ergani_enabled', true)
            ->when($this->option('tenant'), fn ($q, $slug) => $q->where('slug', $slug))
            ->get()
            ->filter(fn (Company $c): bool => filled($c->ergani_username) && filled($c->ergani_password));

        $failed = 0;
        foreach ($companies as $company) {
            $production = clone $company;
            $production->ergani_mode = 'production';   // in memory only — never saved
            try {
                $inSector = (new ErganiClient($production))->employerInfo()['in_card_sector'];
            } catch (\RuntimeException|ConnectionException $e) {
                $this->warn("{$company->slug}: {$e->getMessage()}");
                $failed++;

                continue;
            }

            $was = $company->ergani_card_sector;
            $company->forceFill(['ergani_card_sector' => $inSector, 'ergani_card_sector_checked_at' => now()])->saveQuietly();
            $this->line("{$company->slug}: ".($inSector ? 'ΥΠΟΧΡΕΗ κάρτας' : 'όχι υπόχρεη'));

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

        return $failed > 0 && $failed === $companies->count() ? self::FAILURE : self::SUCCESS;
    }
}
