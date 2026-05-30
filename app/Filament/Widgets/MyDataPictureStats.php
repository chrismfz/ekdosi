<?php

namespace App\Filament\Widgets;

use App\Filament\Widgets\Concerns\FormatsDashboardValues;
use App\Services\MyData\MyDataVatPicture;
use App\Support\MyData\VatPictureCache;
use Carbon\Carbon;
use Filament\Facades\Filament;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

/**
 * "Εικόνα από myDATA — ΦΠΑ": the operator's running VAT position toward the
 * εφορία, AS HELD BY AADE. Έσοδα/ΦΠΑ εκροών (RequestTransmittedDocs) vs
 * Έξοδα/ΦΠΑ εισροών (RequestDocs) vs the net ΦΠΑ, for the CURRENT QUARTER with
 * the CURRENT MONTH alongside.
 *
 * READS the cached snapshot only (VatPictureCache) — the heavy AADE pull runs
 * on the `mydata:refresh-vat-picture` scheduler, never live on a dashboard
 * load. Until the first refresh the cards prompt to sync. The "ενημερώθηκε…"
 * line surfaces snapshot staleness.
 *
 * gr-mydata tenants only.
 */
class MyDataPictureStats extends StatsOverviewWidget
{
    use FormatsDashboardValues;

    protected static ?int $sort = 3;

    protected ?string $heading = 'Εικόνα από myDATA — ΦΠΑ';

    public static function canView(): bool
    {
        return Filament::getTenant()?->einvoice_provider === 'gr-mydata';
    }

    protected function getStats(): array
    {
        $tenant = Filament::getTenant();
        if ($tenant === null) {
            return [];
        }

        $quarter = VatPictureCache::get($tenant, 'quarter');
        $month = VatPictureCache::get($tenant, 'month');

        if ($quarter === null) {
            // Not refreshed yet (no scheduler run / first install).
            return [
                Stat::make('Εικόνα από myDATA', '—')
                    ->description('Δεν έχει συγχρονιστεί ακόμη — εκτελέστε «mydata:refresh-vat-picture».')
                    ->descriptionIcon('heroicon-m-cloud-arrow-down')
                    ->color('gray'),
            ];
        }

        $netQuarter = $quarter->netVat();
        $netMonth = $month?->netVat() ?? 0.0;

        $stats = [
            // NET (καθαρά), so this compares like-for-like with the local
            // "Τρίμηνο — Έσοδα" card (IncomeVsVatStats, which shows net) and
            // with this widget's own breakdown lines (which already use net).
            // Showing gross here made the myDATA picture look ~ one VAT-amount
            // higher than the books — a phantom "discrepancy" that was really
            // just net-vs-gross. ΦΠΑ stays in the description.
            Stat::make('Τρίμηνο — Έσοδα', $this->eur($quarter->outputNet))
                ->description('ΦΠΑ εκροών '.$this->eur($quarter->outputVat).' • μήνας '.$this->eur($month?->outputNet ?? 0))
                ->descriptionIcon('heroicon-m-arrow-up-right')
                ->color('gray'),

            Stat::make('Τρίμηνο — Έξοδα', $this->eur($quarter->inputNet))
                ->description('ΦΠΑ εισροών '.$this->eur($quarter->inputVat).' • μήνας '.$this->eur($month?->inputNet ?? 0))
                ->descriptionIcon('heroicon-m-arrow-down-right')
                ->color('gray'),

            Stat::make('Τρίμηνο — Καθαρό ΦΠΑ', $this->eur(abs($netQuarter)))
                ->description(($quarter->isPayable() ? 'Προς απόδοση' : 'Πιστωτικό υπόλοιπο')
                    .' • μήνας '.$this->eur(abs($netMonth)))
                ->descriptionIcon('heroicon-m-banknotes')
                ->color($quarter->isPayable() ? 'danger' : 'success'),
        ];

        // Self-declared transmitted docs that are NOT sales (μισθοδοσία,
        // ενδοκοινοτικά, ΑΛΠ…) — broken out so they don't inflate Έσοδα.
        foreach ($quarter->breakdown as $b) {
            if ((int) ($b['count'] ?? 0) === 0) {
                continue;
            }
            $vat = (float) ($b['vat'] ?? 0);
            $stats[] = Stat::make('Τρίμηνο — '.($b['label'] ?? 'Λοιπά'), $this->eur((float) ($b['net'] ?? 0)))
                ->description(((int) ($b['count'] ?? 0)).' παραστατικά'
                    .(abs($vat) > 0.005 ? ' • ΦΠΑ '.$this->eur($vat) : ''))
                ->descriptionIcon('heroicon-m-information-circle')
                ->color('gray');
        }

        return $stats;
    }

    protected function getDescription(): ?string
    {
        $tenant = Filament::getTenant();
        $picture = $tenant ? VatPictureCache::get($tenant, 'quarter') : null;

        return $this->stalenessNote($picture);
    }

    private function stalenessNote(?MyDataVatPicture $picture): ?string
    {
        if ($picture?->fetchedAt === null) {
            return null;
        }

        return 'Στοιχεία ΑΑΔΕ — ενημερώθηκε '.Carbon::parse($picture->fetchedAt)->diffForHumans();
    }
}
