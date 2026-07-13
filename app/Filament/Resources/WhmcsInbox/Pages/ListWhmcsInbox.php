<?php

namespace App\Filament\Resources\WhmcsInbox\Pages;

use App\Filament\BaseListRecords;
use App\Filament\Resources\WhmcsInbox\WhmcsInboxResource;
use App\Models\Company;
use App\Services\Whmcs\WhmcsInvoiceFetcher;
use App\Services\Whmcs\WhmcsPaymentSyncer;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;

class ListWhmcsInbox extends BaseListRecords
{
    protected static string $resource = WhmcsInboxResource::class;

    protected function getHeaderActions(): array
    {
        return [
            // On-demand mirror of the scheduled `whmcs:sync-payments` for THIS
            // tenant: ask WHMCS which of our filed, still-open (επί πιστώσει)
            // invoices have since been paid, and close each by recording an
            // ekdosi Payment for its outstanding balance. Money-write in ekdosi
            // only. Same idempotent, only-if-open logic as the cron — so a
            // manual click is always safe (never double-pays, never over-pays).
            Action::make('sync_whmcs_payments')
                ->label('Συγχρονισμός πληρωμών τώρα')
                ->icon('heroicon-o-arrow-down-on-square-stack')
                ->color('gray')
                ->visible(fn () => ($t = Filament::getTenant()) instanceof Company
                    && ($t->hasWhmcsIntegration() || $t->whmcs_fetch_via_bridge))
                ->requiresConfirmation()
                ->modalHeading('Συγχρονισμός πληρωμών από το WHMCS')
                ->modalDescription('Ελέγχει τα εκδοθέντα, WHMCS-συνδεδεμένα τιμολόγια που είναι ακόμη ανοιχτά (επί πιστώσει) και, όσα έχουν πληρωθεί στο WHMCS, τα εξοφλεί εδώ καταγράφοντας πληρωμή για το υπόλοιπό τους. Καμία αλλαγή δεν γίνεται στο WHMCS του πελάτη.')
                ->modalSubmitActionLabel('Συγχρονισμός')
                ->action(function (): void {
                    $tenant = Filament::getTenant();
                    if (! $tenant instanceof Company) {
                        return;
                    }

                    $fetch = app(WhmcsInvoiceFetcher::class)->for($tenant);
                    if ($fetch === null) {
                        Notification::make()
                            ->title('Δεν έχει ρυθμιστεί WHMCS')
                            ->body('Ο πελάτης δεν έχει ενεργή σύνδεση WHMCS — δεν έγινε συγχρονισμός.')
                            ->warning()->send();

                        return;
                    }

                    $result = app(WhmcsPaymentSyncer::class)->syncTenant($tenant, $fetch);

                    if ($result->recorded > 0) {
                        Notification::make()
                            ->title('Καταγράφηκαν πληρωμές')
                            ->body('Εξοφλήθηκαν '.$result->recorded.' τιμολόγια, Σ '.number_format($result->total, 2, ',', '.').' € (ελέγχθηκαν '.$result->checked.').')
                            ->success()->send();
                    } else {
                        Notification::make()
                            ->title('Καμία νέα πληρωμή')
                            ->body('Δεν βρέθηκε ανοιχτό τιμολόγιο που να έχει πληρωθεί στο WHMCS (ελέγχθηκαν '.$result->checked.').')
                            ->info()->send();
                    }
                }),
        ];
    }
}
