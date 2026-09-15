<?php

namespace App\Filament\Resources\WhmcsInbox\Pages;

use App\Filament\BaseListRecords;
use App\Filament\Resources\WhmcsInbox\WhmcsInboxResource;
use App\Models\Company;
use App\Models\PendingWhmcsInvoice;
use App\Services\Whmcs\WhmcsInvoiceFetcher;
use App\Services\Whmcs\WhmcsPaymentSyncer;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Tabs\Tab;
use Illuminate\Database\Eloquent\Builder;

class ListWhmcsInbox extends BaseListRecords
{
    protected static string $resource = WhmcsInboxResource::class;

    /**
     * Status tabs (CFM-style), each with a live count. The FIRST tab is the
     * default view — «Ανοιχτά» = pending_review + held together, because a HELD
     * row (waiting on ΑΦΜ etc.) is just as actionable as a «Προς έλεγχο» one and
     * MUST NOT hide by default (the old default status-filter hid held rows, so
     * one got lost). Tabs own the status filter now (the redundant status
     * SelectFilter was removed).
     */
    public function getTabs(): array
    {
        $tenant = Filament::getTenant();
        $counts = $tenant instanceof Company
            ? PendingWhmcsInvoice::query()
                ->where('company_id', $tenant->getKey())
                ->selectRaw('status, COUNT(*) as aggregate')
                ->groupBy('status')
                ->pluck('aggregate', 'status')
            : collect();

        $n = fn (string ...$statuses): int => (int) array_sum(
            array_map(fn (string $s): int => (int) ($counts[$s] ?? 0), $statuses)
        );

        $open = [PendingWhmcsInvoice::STATUS_PENDING_REVIEW, PendingWhmcsInvoice::STATUS_HELD];

        return [
            // Default: everything still needing an operator — Προς έλεγχο + Σε αναμονή.
            'open' => Tab::make('Ανοιχτά')
                ->icon('heroicon-o-inbox-arrow-down')
                ->badge($n(...$open) ?: null)
                ->badgeColor('warning')
                ->modifyQueryUsing(fn (Builder $query) => $query->whereIn('status', $open)),

            'pending' => Tab::make('Προς έλεγχο')
                ->badge($n(PendingWhmcsInvoice::STATUS_PENDING_REVIEW) ?: null)
                ->badgeColor('warning')
                ->modifyQueryUsing(fn (Builder $query) => $query->where('status', PendingWhmcsInvoice::STATUS_PENDING_REVIEW)),

            'held' => Tab::make('Σε αναμονή')
                ->icon('heroicon-o-pause-circle')
                ->badge($n(PendingWhmcsInvoice::STATUS_HELD) ?: null)
                ->badgeColor('gray')
                ->modifyQueryUsing(fn (Builder $query) => $query->where('status', PendingWhmcsInvoice::STATUS_HELD)),

            'drafted' => Tab::make('Προσχέδια')
                ->badge($n(PendingWhmcsInvoice::STATUS_DRAFTED) ?: null)
                ->badgeColor('info')
                ->modifyQueryUsing(fn (Builder $query) => $query->where('status', PendingWhmcsInvoice::STATUS_DRAFTED)),

            'filed' => Tab::make('Καταχωρημένα')
                ->badge($n(PendingWhmcsInvoice::STATUS_FILED) ?: null)
                ->badgeColor('success')
                ->modifyQueryUsing(fn (Builder $query) => $query->where('status', PendingWhmcsInvoice::STATUS_FILED)),

            // «Αρχειοθετημένα» = the operator-facing name for status `rejected`
            // (kept internally): rows set aside / ignored (δικά μας, φίλων, διπλά…),
            // never filed at AADE, reversible via «Επαναφορά προς έλεγχο».
            'rejected' => Tab::make('Αρχειοθετημένα')
                ->icon('heroicon-o-archive-box')
                ->badge($n(PendingWhmcsInvoice::STATUS_REJECTED) ?: null)
                ->badgeColor('gray')
                ->modifyQueryUsing(fn (Builder $query) => $query->where('status', PendingWhmcsInvoice::STATUS_REJECTED)),

            'split' => Tab::make('Διαχωρισμένα')
                ->badge($n(PendingWhmcsInvoice::STATUS_SPLIT) ?: null)
                ->badgeColor('info')
                ->modifyQueryUsing(fn (Builder $query) => $query->where('status', PendingWhmcsInvoice::STATUS_SPLIT)),

            'resolved' => Tab::make('Ολοκληρωμένα')
                ->badge($n(PendingWhmcsInvoice::STATUS_RESOLVED) ?: null)
                ->badgeColor('success')
                ->modifyQueryUsing(fn (Builder $query) => $query->where('status', PendingWhmcsInvoice::STATUS_RESOLVED)),

            // No modifier → every status for this tenant. Total badge, CFM-style.
            'all' => Tab::make('Όλα')
                ->badge((int) $counts->sum() ?: null),
        ];
    }

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
                // Money-write: this closes receivables tenant-wide, so it needs the
                // same permission as the inbox's mutating per-record actions
                // (Update:PendingWhmcsInvoice) — read-only inbox access must NOT
                // reach it (the per-invoice «Έχει πληρωθεί;» is likewise gated).
                ->authorize(fn () => (bool) auth()->user()?->can('Update:PendingWhmcsInvoice'))
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
