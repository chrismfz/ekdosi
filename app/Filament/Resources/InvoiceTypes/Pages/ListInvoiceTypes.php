<?php

namespace App\Filament\Resources\InvoiceTypes\Pages;

use App\Filament\Resources\InvoiceTypes\InvoiceTypeResource;
use App\Services\MyData\MyDataLookupSeeder;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use App\Filament\BaseListRecords;

class ListInvoiceTypes extends BaseListRecords
{
    protected static string $resource = InvoiceTypeResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),

            // Seed a starter set of the common §8.1 invoice types (goods sale,
            // service, retail, credit, delivery note) so a fresh tenant can
            // actually issue — e.g. ΤΙΜ (1.1 Τιμολόγιο Πώλησης) for goods, not
            // just ΤΠΥ. Idempotent: matches by code, keeps operator edits.
            Action::make('seed_standard')
                ->label('Εισαγωγή τυπικών (ΑΑΔΕ §8.1)')
                ->icon('heroicon-o-arrow-down-tray')
                ->color('gray')
                ->requiresConfirmation()
                ->modalHeading('Εισαγωγή τυπικών τύπων παραστατικών')
                ->modalDescription('Προστίθεται ένα βασικό σετ: Τιμολόγιο Πώλησης (εμπόρευμα), Τιμολόγιο/Δελτίο Αποστολής, Παροχής Υπηρεσιών, Λιανικής, Πιστωτικό, Δελτίο Αποστολής. Υπάρχοντες (ίδιος κωδικός σειράς) διατηρούνται. Οι σειρές/κωδικοί επεξεργάζονται μετά.')
                ->modalSubmitActionLabel('Εισαγωγή')
                ->action(function (): void {
                    $tenant = Filament::getTenant();
                    if (! $tenant) {
                        return;
                    }
                    $r = app(MyDataLookupSeeder::class)->seedInvoiceTypes($tenant);
                    Notification::make()
                        ->title("Προστέθηκαν {$r['created']} · Υπήρχαν ήδη {$r['skipped']}")
                        ->success()->send();
                }),
        ];
    }
}
