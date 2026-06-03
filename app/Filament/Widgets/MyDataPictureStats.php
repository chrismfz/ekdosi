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
            // Headline = NET (καθαρά), so it compares like-for-like with the
            // local "Τρίμηνο — Έσοδα (καθαρά)" card (IncomeVsVatStats) and with
            // this widget's own breakdown lines. The «(καθαρά)» label + the
            // «Με ΦΠΑ …» (gross) in the description spell out each figure, so
            // an operator never reads the net total as if it were gross — the
            // confusion that made the picture look ~ one VAT-amount off.
            Stat::make('Τρίμηνο — Έσοδα (καθαρά)', $this->eur($quarter->outputNet))
                ->description('Με ΦΠΑ '.$this->eur($quarter->outputGross)
                    .' • ΦΠΑ εκροών '.$this->eur($quarter->outputVat)
                    .' • μήνας '.$this->eur($month?->outputNet ?? 0).' καθ.')
                ->descriptionIcon('heroicon-m-arrow-up-right')
                ->color('gray'),

            Stat::make('Τρίμηνο — Έξοδα (καθαρά)', $this->eur($quarter->inputNet))
                ->description('Με ΦΠΑ '.$this->eur($quarter->inputGross)
                    .' • ΦΠΑ εισροών '.$this->eur($quarter->inputVat)
                    .' • μήνας '.$this->eur($month?->inputNet ?? 0).' καθ.')
                ->descriptionIcon('heroicon-m-arrow-down-right')
                ->color('gray'),

            // Card colour follows the headline = the QUARTER (a Stat card has a
            // single colour). The MONTH figure carries its OWN word so a credit
            // month inside a payable quarter never reads as an amount owed —
            // e.g. «Προς απόδοση • μήνας: πίστωση 9,70 €» (net month = εκροών −
            // εισροών = −9,70 → πίστωση, not 9,70 owed).
            Stat::make('Τρίμηνο — Καθαρό ΦΠΑ', $this->eur(abs($netQuarter)))
                ->description(($quarter->isPayable() ? 'Προς απόδοση' : 'Πιστωτικό υπόλοιπο')
                    .' • μήνας: '.$this->monthVatLabel($netMonth))
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

    /**
     * Per-period VAT label for the month, judged on ITS OWN sign (not the
     * quarter's): positive net = προς απόδοση, negative = πίστωση, ~0 = μηδέν.
     */
    private function monthVatLabel(float $netMonth): string
    {
        if (abs($netMonth) < 0.005) {
            return $this->eur(0);
        }

        $word = $netMonth > 0 ? 'προς απόδοση' : 'πίστωση';

        return $word.' '.$this->eur(abs($netMonth));
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
