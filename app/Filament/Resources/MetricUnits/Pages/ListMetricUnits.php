<?php

namespace App\Filament\Resources\MetricUnits\Pages;

use App\Filament\BaseListRecords;
use App\Filament\Resources\MetricUnits\MetricUnitResource;
use App\Services\MyData\MyDataLookupSeeder;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;

class ListMetricUnits extends BaseListRecords
{
    protected static string $resource = MetricUnitResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),

            // Practical units: §8.13 quantities + common service units
            // (ΩΡΑ/ΜΗΝΑΣ/ΕΤΟΣ/ΥΠΗΡΕΣΙΑ). Idempotent: existing rows (by name) kept.
            Action::make('seed_standard')
                ->label('Εισαγωγή τυπικών')
                ->icon('heroicon-o-arrow-down-tray')
                ->color('gray')
                ->requiresConfirmation()
                ->modalHeading('Εισαγωγή τυπικών μονάδων μέτρησης')
                ->modalDescription('Προστίθενται κοινές μονάδες (ΤΕΜ, ΥΠΗΡΕΣΙΑ, ΩΡΑ, ΜΗΝΑΣ, ΚΙΛΟ, ΛΙΤΡΟ, ΜΕΤΡΟ, Μ², Μ³…). Υπάρχουσες διατηρούνται — δεν διπλασιάζονται.')
                ->modalSubmitActionLabel('Εισαγωγή')
                ->action(function (): void {
                    $tenant = Filament::getTenant();
                    if (! $tenant) {
                        return;
                    }
                    $r = app(MyDataLookupSeeder::class)->seedMetricUnits($tenant);
                    Notification::make()
                        ->title("Προστέθηκαν {$r['created']} · Υπήρχαν ήδη {$r['skipped']}")
                        ->success()->send();
                }),
        ];
    }
}
