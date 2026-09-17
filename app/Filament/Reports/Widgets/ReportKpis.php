<?php

namespace App\Filament\Reports\Widgets;

use App\Models\Company;
use App\Support\Dashboard\DashboardMetricsCache;
use App\Support\Dashboard\ReportFilters;
use App\Support\Money;
use Filament\Facades\Filament;
use Filament\Widgets\Concerns\InteractsWithPageFilters;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

/**
 * The Reports scorecard: the year's headline KPIs in one row —
 * τζίρος (καθαρά) + YoY, μέσος μηνιαίος / μέση αξία παραστατικού,
 * ανεξόφλητα + DSO, ποσοστό πιστωτικών, συγκέντρωση #1 πελάτη.
 *
 * Reads the Reports page year filter (ReportFilters). All money labelled
 * «καθαρά» to stay consistent with the rest of the dashboard (the net-vs-
 * gross lesson). Lives outside app/Filament/Widgets on purpose so it is
 * NOT auto-discovered onto the main dashboard — the Reports page lists it
 * explicitly in getWidgets().
 */
class ReportKpis extends StatsOverviewWidget
{
    use InteractsWithPageFilters;

    protected static ?int $sort = 1;

    protected int|string|array $columnSpan = 'full';

    protected function getStats(): array
    {
        $tenant = Filament::getTenant();
        if (! $tenant instanceof Company) {
            return [];
        }

        $year = ReportFilters::year($this->pageFilters);
        $k = DashboardMetricsCache::for($tenant)->kpiSummary($year);

        $yoy = $k['yoyPct'];
        $yoyText = $yoy === null
            ? 'χωρίς σύγκριση πέρσι'
            : sprintf('%+.1f%% vs %d (%s)', $yoy, $year - 1, Money::eur($k['priorNet']));
        $yoyColor = $yoy === null ? 'gray' : ($yoy >= 0 ? 'success' : 'danger');
        $yoyIcon = $yoy === null
            ? 'heroicon-m-minus'
            : ($yoy >= 0 ? 'heroicon-m-arrow-trending-up' : 'heroicon-m-arrow-trending-down');

        $share = $k['topCustomerShare'];

        return [
            Stat::make("Τζίρος $year (καθαρά)", Money::eur($k['net']))
                ->description($yoyText)
                ->descriptionIcon($yoyIcon)
                ->color($yoyColor),

            Stat::make('Μέσος μηνιαίος (καθαρά)', Money::eur($k['avgMonthlyNet']))
                ->description($k['count'].' παραστατικά • μέση αξία '.Money::eur($k['avgInvoiceNet']))
                ->descriptionIcon('heroicon-m-calculator')
                ->color('gray'),

            Stat::make('Ανεξόφλητα (πιστωτικά)', Money::eur($k['receivables']))
                ->description($k['dsoDays'] !== null
                    ? 'DSO ~'.$k['dsoDays'].' ημέρες είσπραξης'
                    : 'υπόλοιπο πελατών με πίστωση')
                ->descriptionIcon('heroicon-m-banknotes')
                ->color($k['receivables'] > 0.005 ? 'danger' : 'success'),

            Stat::make('Πιστωτικά', number_format($k['creditRatioPct'], 1, ',', '.').'%')
                ->description(Money::eur($k['creditGross']).' επί του τζίρου '.$year)
                ->descriptionIcon('heroicon-m-arrow-uturn-left')
                ->color($k['creditRatioPct'] > 10 ? 'warning' : 'gray'),

            Stat::make('Συγκέντρωση #1 πελάτη', $share === null ? '—' : number_format($share, 1, ',', '.').'%')
                ->description($k['topCustomerName'] ?? 'καμία πώληση στο διάστημα')
                ->descriptionIcon('heroicon-m-user-group')
                ->color($share !== null && $share > 25 ? 'warning' : 'gray'),
        ];
    }
}
