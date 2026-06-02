<?php

namespace App\Filament\Resources\DistributionAims\Pages;

use App\Filament\BaseListRecords;
use App\Filament\Resources\DistributionAims\DistributionAimResource;
use App\Services\MyData\MyDataLookupSeeder;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;

class ListDistributionAims extends BaseListRecords
{
    protected static string $resource = DistributionAimResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),

            // Common AADE movePurpose values (Πώληση first). Idempotent:
            // existing rows (by description) are kept, never duplicated.
            Action::make('seed_standard')
                ->label('Εισαγωγή τυπικών')
                ->icon('heroicon-o-arrow-down-tray')
                ->color('gray')
                ->requiresConfirmation()
                ->modalHeading('Εισαγωγή τυπικών σκοπών διακίνησης')
                ->modalDescription('Προστίθενται οι συνηθισμένοι σκοποί διακίνησης της ΑΑΔΕ (Πώληση, Πώληση για Λογ. Τρίτων, Δειγματισμός, Επιστροφή…). Υπάρχοντες διατηρούνται — δεν διπλασιάζονται.')
                ->modalSubmitActionLabel('Εισαγωγή')
                ->action(function (): void {
                    $tenant = Filament::getTenant();
                    if (! $tenant) {
                        return;
                    }
                    $r = app(MyDataLookupSeeder::class)->seedDistributionAims($tenant);
                    Notification::make()
                        ->title("Προστέθηκαν {$r['created']} · Υπήρχαν ήδη {$r['skipped']}")
                        ->success()->send();
                }),
        ];
    }
}
