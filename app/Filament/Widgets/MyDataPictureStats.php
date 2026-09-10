<?php

namespace App\Filament\Widgets;

use App\Filament\Widgets\Concerns\BuildsProviderQuotaStat;
use App\Filament\Widgets\Concerns\FormatsDashboardValues;
use App\Models\Company;
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
 * Any tenant that can READ from myDATA — direct gr-mydata OR a gr-provider
 * tenant reading its own AADE picture with its own subscription (the provider
 * only files; the documents are still the tenant's).
 *
 * A gr-provider tenant ALSO gets the «Πάροχος — Υπόλοιπο εκδόσεων» card appended
 * here (BuildsProviderQuotaStat), so the provider quota rides this high row instead
 * of its own widget near the bottom of the dashboard. ProviderQuotaStats stands down
 * whenever this widget is visible, so the card shows exactly once.
 */
class MyDataPictureStats extends StatsOverviewWidget
{
    use BuildsProviderQuotaStat;
    use FormatsDashboardValues;

    protected static ?int $sort = 3;

    protected ?string $heading = 'Εικόνα από myDATA — ΦΠΑ';

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

        $quarter = VatPictureCache::get($tenant, 'quarter');
        $month = VatPictureCache::get($tenant, 'month');

        if ($quarter === null) {
            // Not refreshed yet (no scheduler run / first install). The provider quota
            // is unrelated to the AADE snapshot, so it still shows.
            return $this->withProviderQuota($tenant, [
                Stat::make('Εικόνα από myDATA', '—')
                    ->description('Δεν έχει συγχρονιστεί ακόμη — εκτελέστε «mydata:refresh-vat-picture».')
                    ->descriptionIcon('heroicon-m-cloud-arrow-down')
                    ->color('gray'),
            ]);
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

        // 4th card for provider tenants: the ΥΠΑΗΕΣ issuance quota. Placed before the
        // optional breakdown extras so it sits right after the three headline ΦΠΑ
        // cards rather than trailing a variable number of «Τρίμηνο — …» ones. With no
        // breakdown that's a clean 4-across row (Filament's getColumns() picks 4 when
        // count % 3 === 1); with breakdown lines the grid falls back to 3-across and
        // the card wraps to the next row — still high on the page, which is the point.
        $stats = $this->withProviderQuota($tenant, $stats);

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
     * Append the provider-quota card for a gr-provider tenant; a no-op for everyone
     * else (a direct gr-mydata tenant has no provider account, hence no quota).
     *
     * @param  array<int, Stat>  $stats
     * @return array<int, Stat>
     */
    private function withProviderQuota(Company $tenant, array $stats): array
    {
        if ($tenant->einvoice_provider !== 'gr-provider') {
            return $stats;
        }

        $stats[] = $this->providerQuotaStat($tenant, 'Πάροχος — Υπόλοιπο εκδόσεων');

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

        // «(ΦΠΑ)» is load-bearing: the row can also carry the provider-quota card,
        // whose age is the last FILING, not this AADE snapshot. Scoping the note to
        // the ΦΠΑ figures stops it from vouching for a number it doesn't cover.
        return 'Στοιχεία ΑΑΔΕ (ΦΠΑ) — ενημερώθηκε '.Carbon::parse($picture->fetchedAt)->diffForHumans();
    }
}
