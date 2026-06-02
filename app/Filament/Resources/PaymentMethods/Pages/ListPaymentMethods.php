<?php

namespace App\Filament\Resources\PaymentMethods\Pages;

use App\Filament\BaseListRecords;
use App\Filament\Resources\PaymentMethods\PaymentMethodResource;
use App\Services\MyData\MyDataLookupSeeder;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;

class ListPaymentMethods extends BaseListRecords
{
    protected static string $resource = PaymentMethodResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),

            // Seed the standard §8.12 myDATA payment methods (1–8), each with its
            // myDATA type set. Idempotent: existing rows (matched by description)
            // are kept, never duplicated.
            Action::make('seed_standard')
                ->label('Εισαγωγή τυπικών (ΑΑΔΕ §8.12)')
                ->icon('heroicon-o-arrow-down-tray')
                ->color('gray')
                ->requiresConfirmation()
                ->modalHeading('Εισαγωγή τυπικών τρόπων πληρωμής')
                ->modalDescription('Προστίθενται οι 8 τυπικοί τρόποι πληρωμής της ΑΑΔΕ (Μετρητά, POS/e-POS, Επί Πιστώσει, IRIS…) με τον κωδικό myDATA τους. Υπάρχοντες διατηρούνται — δεν διπλασιάζονται.')
                ->modalSubmitActionLabel('Εισαγωγή')
                ->action(function (): void {
                    $tenant = Filament::getTenant();
                    if (! $tenant) {
                        return;
                    }
                    $r = app(MyDataLookupSeeder::class)->seedPaymentMethods($tenant);
                    Notification::make()
                        ->title("Προστέθηκαν {$r['created']} · Υπήρχαν ήδη {$r['skipped']}")
                        ->success()->send();
                }),
        ];
    }
}
