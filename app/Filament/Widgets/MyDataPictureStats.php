<?php

namespace App\Filament\Widgets;

use App\Filament\Widgets\Concerns\FormatsDashboardValues;
use App\Models\Company;
use App\Services\Dashboard\VatPeriodReport;
use Filament\Facades\Filament;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

/**
 * "Εικόνα από myDATA" (E6) — the operator's running ΦΠΑ position toward the
 * εφορία, at a glance: Έσοδα (ΦΠΑ εκροών) vs Έξοδα (ΦΠΑ εισροών) vs the net
 * ΦΠΑ to pay. Shown for the CURRENT QUARTER, with the CURRENT MONTH alongside
 * in each stat's description.
 *
 * Output side = our invoices; input side = local `expenses` (the Έξοδα phase).
 * Figures are LOCAL (the AADE RequestVatInfo cross-check is a follow-up).
 *
 * Only meaningful for gr-mydata tenants (myDATA is the data source); hidden
 * otherwise.
 */
class MyDataPictureStats extends StatsOverviewWidget
{
    use FormatsDashboardValues;

    protected static ?int $sort = 7;

    protected ?string $heading = 'Εικόνα από myDATA — ΦΠΑ';

    public static function canView(): bool
    {
        return Filament::getTenant()?->einvoice_provider === 'gr-mydata';
    }

    protected function getStats(): array
    {
        $tenant = Filament::getTenant();
        if (! $tenant instanceof Company) {
            return [];
        }

        $report = new VatPeriodReport($tenant);
        $now = now();

        $quarter = $report->forPeriod($now->copy()->startOfQuarter(), $now->copy()->endOfQuarter());
        $month = $report->forPeriod($now->copy()->startOfMonth(), $now->copy()->endOfMonth());

        $netQuarter = $quarter->netVat();
        $netMonth = $month->netVat();

        // Net ΦΠΑ: positive = προς απόδοση (owe), negative = πιστωτικό (credit).
        $netLabel = $quarter->isPayable() ? 'Προς απόδοση' : 'Πιστωτικό υπόλοιπο';
        $netColor = $quarter->isPayable() ? 'danger' : 'success';

        return [
            Stat::make('Τρίμηνο — Έσοδα', $this->eur($quarter->outputGross))
                ->description('ΦΠΑ εκροών '.$this->eur($quarter->outputVat).' • μήνας '.$this->eur($month->outputGross))
                ->descriptionIcon('heroicon-m-arrow-up-right')
                ->color('gray'),

            Stat::make('Τρίμηνο — Έξοδα', $this->eur($quarter->inputGross))
                ->description('ΦΠΑ εισροών '.$this->eur($quarter->inputVat).' • μήνας '.$this->eur($month->inputGross))
                ->descriptionIcon('heroicon-m-arrow-down-right')
                ->color('gray'),

            Stat::make('Τρίμηνο — Καθαρό ΦΠΑ', $this->eur(abs($netQuarter)))
                ->description($netLabel.' • μήνας '.$this->eur(abs($netMonth)))
                ->descriptionIcon('heroicon-m-banknotes')
                ->color($netColor),
        ];
    }
}
