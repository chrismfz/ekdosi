<?php

namespace App\Filament\Widgets;

use App\Filament\Pages\MyDataConsole;
use App\Filament\Pages\MyDataReconciliation;
use App\Filament\Resources\DeliveryNotes\DeliveryNoteResource;
use App\Filament\Resources\Invoices\InvoiceResource;
use App\Models\DeliveryNote;
use App\Models\Invoice;
use Carbon\Carbon;
use Filament\Facades\Filament;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

/**
 * «Συγχρονισμός myDATA» — the operator's at-a-glance AADE-sync cockpit. Each card
 * is a one-click jump to the worklist that resolves it:
 *   - Παραστατικά / Δελτία «προς υποβολή» (the Outbox: live docs that SHOULD be
 *     filed but carry no MARK) → the pre-filtered «Προς υποβολή» list tab.
 *   - Τοπικές ασυμφωνίες (local_status ↔ recorded mydata_state) → the local check.
 *   - Διασταύρωση με AADE (freshness + discrepancies of the last live reconcile,
 *     read from the scheduled-fetch cache) → the live console.
 *
 * Counts are cheap COUNT(*) / cache reads — no live AADE call on dashboard load.
 * Visible to any tenant that can READ from myDATA.
 */
class MyDataSyncStats extends StatsOverviewWidget
{
    protected static ?int $sort = 2;

    protected ?string $heading = 'Συγχρονισμός myDATA';

    public static function canView(): bool
    {
        return (bool) Filament::getTenant()?->canReadMyData();
    }

    protected function getStats(): array
    {
        $tenant = Filament::getTenant();
        if ($tenant === null) {
            return [];
        }

        $stats = [$this->invoiceOutboxStat($tenant)];

        if (($deliveries = DeliveryNote::query()->awaitingMyData()->count()) > 0) {
            $stats[] = Stat::make('Δελτία προς υποβολή', (string) $deliveries)
                ->description('Δελτία διακίνησης χωρίς ΜΑΡΚ')
                ->descriptionIcon('heroicon-m-truck')
                ->color('warning')
                ->url(DeliveryNoteResource::getUrl('index', ['tenant' => $tenant]).'?tab=outbox');
        }

        $stats[] = $this->localMismatchStat($tenant);
        $stats[] = $this->aadeCrossCheckStat($tenant);

        return $stats;
    }

    private function invoiceOutboxStat($tenant): Stat
    {
        $count = Invoice::query()->awaitingMyData()->count();

        return Stat::make('Παραστατικά προς υποβολή', (string) $count)
            ->description($count > 0 ? 'Πρόχειρα + χωρίς ΜΑΡΚ ενώ θα έπρεπε' : 'Όλα υποβλήθηκαν')
            ->descriptionIcon('heroicon-m-cloud-arrow-up')
            ->color($count > 0 ? 'warning' : 'success')
            ->url(InvoiceResource::getUrl('index', ['tenant' => $tenant]).'?tab=outbox');
    }

    private function localMismatchStat($tenant): Stat
    {
        $mismatches = MyDataReconciliation::mismatchCount();

        return Stat::make('Τοπικές ασυμφωνίες', (string) $mismatches)
            ->description($mismatches > 0 ? 'Τοπική κατάσταση ↔ myDATA' : 'Καμία ασυμφωνία')
            ->descriptionIcon('heroicon-m-scale')
            ->color($mismatches > 0 ? 'danger' : 'success')
            ->url(MyDataReconciliation::getUrl(['tenant' => $tenant]));
    }

    private function aadeCrossCheckStat($tenant): Stat
    {
        $at = MyDataConsole::lastFetchAt($tenant->getKey());
        $url = MyDataConsole::getUrl(['tenant' => $tenant]);

        if ($at === null) {
            return Stat::make('Διασταύρωση με AADE', '—')
                ->description('Δεν έχει γίνει έλεγχος — «Ανανέωση όλων»')
                ->descriptionIcon('heroicon-m-cloud-arrow-down')
                ->color('gray')
                ->url($url);
        }

        // lastFetchAt and lastFetchState read the cache independently — guard a
        // partial/evicted body (timestamp present, state gone) so we never index null.
        $result = MyDataConsole::lastFetchState($tenant->getKey())['result'] ?? [];
        $discrepancies = (int) ($result['discrepancyCount'] ?? 0);
        $orphanIncome = 0;
        foreach ($result['missingLocally'] ?? [] as $row) {
            if (($row['bucket'] ?? null) === 'income') {
                $orphanIncome++;
            }
        }

        $parts = [];
        $parts[] = $discrepancies > 0 ? "{$discrepancies} ασυμφωνίες" : 'καμία ασυμφωνία';
        if ($orphanIncome > 0) {
            $parts[] = "{$orphanIncome} αδέσποτα πωλήσεων";
        }

        return Stat::make('Διασταύρωση με AADE', Carbon::parse($at)->diffForHumans())
            ->description(ucfirst(implode(' · ', $parts)))
            ->descriptionIcon('heroicon-m-cloud')
            ->color(($discrepancies > 0 || $orphanIncome > 0) ? 'warning' : 'success')
            ->url($url);
    }
}
