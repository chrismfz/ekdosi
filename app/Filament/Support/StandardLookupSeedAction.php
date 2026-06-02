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
     *                                 ['created' => int, 'skipped' => int]
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
                Notification::make()
                    ->title("Προστέθηκαν {$r['created']} · Υπήρχαν ήδη {$r['skipped']}")
                    ->success()->send();
            });
    }
}
