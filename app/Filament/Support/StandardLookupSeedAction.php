<?php

namespace App\Filament\Support;

use App\Services\MyData\MyDataLookupSeeder;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;

/**
 * Shared «Εισαγωγή τυπικών» header action for the Setup lookup lists — seeds
 * the standard AADE values for the current tenant via a MyDataLookupSeeder
 * method. Idempotent (the seeder matches by natural key, never duplicates), so
 * the action is safe to run repeatedly. One place to keep the confirm-modal +
 * notification consistent across all the lookup resources.
 */
class StandardLookupSeedAction
{
    /**
     * @param  string  $seederMethod  a MyDataLookupSeeder method returning
     *                                ['created' => int, 'skipped' => int]
     */
    public static function make(string $seederMethod, string $label, string $modalHeading, string $modalDescription): Action
    {
        return Action::make('seed_standard')
            ->label($label)
            ->icon('heroicon-o-arrow-down-tray')
            ->color('gray')
            ->requiresConfirmation()
            ->modalHeading($modalHeading)
            ->modalDescription($modalDescription)
            ->modalSubmitActionLabel('Εισαγωγή')
            ->action(function () use ($seederMethod): void {
                $tenant = Filament::getTenant();
                if (! $tenant) {
                    return;
                }
                $r = app(MyDataLookupSeeder::class)->{$seederMethod}($tenant);
                // Some seeders (e.g. invoice types) also FILL-EMPTY missing myDATA
                // classification on pre-existing rows — surface that count, or a
                // back-fill-only run reads a misleading «Προστέθηκαν 0» toast.
                $filled = $r['filled'] ?? 0;
                $title = "Προστέθηκαν {$r['created']}"
                    .($filled > 0 ? " · συμπληρώθηκαν {$filled}" : '')
                    ." · Υπήρχαν ήδη {$r['skipped']}";
                Notification::make()->title($title)->success()->send();
            });
    }
}
