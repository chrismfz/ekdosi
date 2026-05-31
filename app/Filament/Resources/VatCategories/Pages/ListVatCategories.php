<?php

namespace App\Filament\Resources\VatCategories\Pages;

use App\Filament\Resources\VatCategories\VatCategoryResource;
use App\Services\MyData\MyDataLookupSeeder;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use App\Filament\BaseListRecords;

class ListVatCategories extends BaseListRecords
{
    protected static string $resource = VatCategoryResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),

            // Seed the standard Greek §8.2 VAT categories. There is no myDATA
            // "fetch rates" API — §8.2 is a static spec enum — so this seeds
            // from the committed Codes table. Idempotent: existing rates kept.
            Action::make('seed_standard')
                ->label('Εισαγωγή τυπικών (ΑΑΔΕ §8.2)')
                ->icon('heroicon-o-arrow-down-tray')
                ->color('gray')
                ->requiresConfirmation()
                ->modalHeading('Εισαγωγή τυπικών κατηγοριών ΦΠΑ')
                ->modalDescription('Προστίθενται οι τυπικοί συντελεστές (24/13/6/17/9/4/0%) από τον πίνακα §8.2 της ΑΑΔΕ. Υπάρχοντες συντελεστές διατηρούνται — δεν αντικαθίστανται ούτε διπλασιάζονται.')
                ->modalSubmitActionLabel('Εισαγωγή')
                ->action(function (): void {
                    $tenant = Filament::getTenant();
                    if (! $tenant) {
                        return;
                    }
                    $r = app(MyDataLookupSeeder::class)->seedVatCategories($tenant);
                    Notification::make()
                        ->title("Προστέθηκαν {$r['created']} · Υπήρχαν ήδη {$r['skipped']}")
                        ->success()->send();
                }),
        ];
    }
}
