<?php

namespace App\Filament\Resources\InvoiceTypes\Pages;

use App\Filament\BaseListRecords;
use App\Filament\Resources\InvoiceTypes\InvoiceTypeResource;
use App\Models\InvoiceType;
use App\Services\MyData\MyDataLookupSeeder;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;

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
                ->modalDescription('Προστίθεται ένα βασικό σετ: Τιμολόγιο Πώλησης (εμπόρευμα), Παροχής Υπηρεσιών, Λιανικής, Πιστωτικό, Δελτίο Αποστολής. Σε όσους τύπους υπάρχουν ήδη αλλά ΔΕΝ έχουν κατηγορία myDATA, συμπληρώνεται η κατηγορία (π.χ. 1.1 στο ΤΙΜ) — όσοι την έχουν ήδη μένουν ως έχουν. Οι σειρές/κωδικοί επεξεργάζονται μετά.')
                ->modalSubmitActionLabel('Εισαγωγή')
                ->action(function (): void {
                    $tenant = Filament::getTenant();
                    if (! $tenant) {
                        return;
                    }
                    $r = app(MyDataLookupSeeder::class)->seedInvoiceTypes($tenant);
                    Notification::make()
                        ->title("Προστέθηκαν {$r['created']} · Συμπληρώθηκε myDATA σε {$r['filled']} · Υπήρχαν ήδη {$r['skipped']}")
                        ->success()->send();
                }),

            // One-click "show everything in the menu" — clears the off-menu
            // clutter from a legacy import so the operator starts from all-on
            // and hides individually. Tenant-scoped, only flips the hidden ones.
            Action::make('show_all_on_menu')
                ->label('Εμφάνιση όλων στο μενού')
                ->icon('heroicon-o-eye')
                ->color('gray')
                ->requiresConfirmation()
                ->modalHeading('Εμφάνιση όλων των τύπων στο μενού')
                ->modalDescription('Όλοι οι τύποι παραστατικών θα εμφανίζονται στο μενού έκδοσης. Μπορείτε μετά να κρύψετε όποιους δεν χρειάζεστε μεμονωμένα.')
                ->modalSubmitActionLabel('Εμφάνιση όλων')
                ->action(function (): void {
                    $tenant = Filament::getTenant();
                    if (! $tenant) {
                        return;
                    }
                    $flipped = InvoiceType::query()
                        ->where('company_id', $tenant->getKey())
                        ->where('show_on_menu', false)
                        ->update(['show_on_menu' => true]);

                    Notification::make()
                        ->title($flipped > 0 ? "Εμφανίστηκαν {$flipped} τύποι" : 'Όλοι ήταν ήδη στο μενού')
                        ->success()->send();
                }),
        ];
    }
}
