<?php

namespace App\Filament\Resources\DeliveryMethods\Pages;

use App\Filament\BaseListRecords;
use App\Filament\Resources\DeliveryMethods\DeliveryMethodResource;
use App\Services\MyData\MyDataLookupSeeder;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;

class ListDeliveryMethods extends BaseListRecords
{
    protected static string $resource = DeliveryMethodResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),

            // Common delivery methods (NOT an AADE-codified table — sensible
            // defaults). Idempotent: existing rows (by description) are kept.
            Action::make('seed_standard')
                ->label('Εισαγωγή τυπικών')
                ->icon('heroicon-o-arrow-down-tray')
                ->color('gray')
                ->requiresConfirmation()
                ->modalHeading('Εισαγωγή τυπικών τρόπων αποστολής')
                ->modalDescription('Προστίθενται κοινοί τρόποι αποστολής (Παραλαβή από κατάστημα, Courier, ΕΛΤΑ, Ηλεκτρονική παράδοση…). Υπάρχοντες διατηρούνται — δεν διπλασιάζονται.')
                ->modalSubmitActionLabel('Εισαγωγή')
                ->action(function (): void {
                    $tenant = Filament::getTenant();
                    if (! $tenant) {
                        return;
                    }
                    $r = app(MyDataLookupSeeder::class)->seedDeliveryMethods($tenant);
                    Notification::make()
                        ->title("Προστέθηκαν {$r['created']} · Υπήρχαν ήδη {$r['skipped']}")
                        ->success()->send();
                }),
        ];
    }
}
