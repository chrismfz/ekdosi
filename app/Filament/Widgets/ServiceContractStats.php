<?php

namespace App\Filament\Widgets;

use App\Filament\Widgets\Concerns\FormatsDashboardValues;
use App\Models\Company;
use App\Services\ServiceContractInsights;
use Filament\Facades\Filament;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

/**
 * «Υπηρεσίες» headline stats for the current tenant: active / suspended
 * contract counts, the 30-day renewal pipeline, and the MRR (μηνιαίο
 * επαναλαμβανόμενο έσοδο) — the recurring-revenue headline. All figures come
 * from {@see ServiceContractInsights} (the testable home of the MRR sum),
 * explicitly scoped by company_id. Read-only.
 */
class ServiceContractStats extends StatsOverviewWidget
{
    use FormatsDashboardValues;

    // After the money headline (IncomeStatsOverview = 1) — recurring revenue
    // is the second-most-important figure for this app.
    protected static ?int $sort = 2;

    protected ?string $pollingInterval = '60s';

    protected function getStats(): array
    {
        $tenant = Filament::getTenant();
        if (! $tenant instanceof Company) {
            return [];
        }

        $insights = new ServiceContractInsights($tenant->getKey());

        $active = $insights->activeCount();
        $suspended = $insights->suspendedCount();
        $dueSoon = $insights->renewalsDueWithin(30);
        $mrr = $insights->monthlyRecurringRevenue();

        return [
            Stat::make('Ενεργές υπηρεσίες', (string) $active)
                ->description('Συμβόλαια σε ισχύ')
                ->descriptionIcon('heroicon-m-arrow-path-rounded-square')
                ->color('success'),

            Stat::make('Σε αναστολή', (string) $suspended)
                ->description($suspended > 0 ? 'Χρειάζονται προσοχή' : 'Καμία σε αναστολή')
                ->descriptionIcon('heroicon-m-pause-circle')
                ->color($suspended > 0 ? 'warning' : 'gray'),

            Stat::make('Ανανεώσεις (30 ημέρες)', (string) $dueSoon)
                ->description('Επόμενη χρέωση εντός 30 ημερών')
                ->descriptionIcon('heroicon-m-calendar-days')
                ->color($dueSoon > 0 ? 'primary' : 'gray'),

            Stat::make('MRR (μηνιαίο έσοδο)', $this->eur($mrr))
                ->description('Επαναλαμβανόμενο έσοδο / μήνα (καθαρό)')
                ->descriptionIcon('heroicon-m-banknotes')
                ->color('primary'),
        ];
    }
}
