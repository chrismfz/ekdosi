<?php

namespace App\Filament\Widgets;

use App\Filament\Resources\Customers\CustomerResource;
use App\Filament\Resources\Invoices\InvoiceResource;
use App\Filament\Widgets\Concerns\FormatsDashboardValues;
use App\Models\Company;
use App\Services\Dashboard\DashboardMetrics;
use App\Support\TableFilterUrl;
use Filament\Facades\Filament;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

/**
 * Headline money stats for the current tenant: this-month net income
 * (with trend vs last month + a 6-month sparkline), this-month output
 * VAT, outstanding receivables, and the invoice count + myDATA-unfiled
 * backlog. The first thing an operator should see.
 */
class IncomeStatsOverview extends StatsOverviewWidget
{
    use FormatsDashboardValues;

    protected static ?int $sort = 1;

    // Keep the headline figures live without a manual refresh.
    protected ?string $pollingInterval = '60s';

    protected function getStats(): array
    {
        $tenant = Filament::getTenant();
        if (! $tenant instanceof Company) {
            return [];
        }

        $metrics = new DashboardMetrics($tenant);
        $now = now();

        $thisMonth = $metrics->income($now->copy()->startOfMonth(), $now->copy()->endOfMonth());
        $lastMonth = $metrics->income(
            $now->copy()->subMonthNoOverflow()->startOfMonth(),
            $now->copy()->subMonthNoOverflow()->endOfMonth(),
        );

        $series = $metrics->monthlyIncome(6);
        $netSpark = array_column($series, 'net');
        $vatSpark = array_column($series, 'vat');

        $outstanding = $metrics->outstandingReceivables();

        $stats = [
            Stat::make('Έσοδα μήνα (καθαρά)', $this->eur($thisMonth->net))
                ->description($this->trendText($thisMonth->net, $lastMonth->net).' vs προηγ. μήνα')
                ->descriptionIcon($this->trendIcon($thisMonth->net, $lastMonth->net))
                ->color($this->trendColor($thisMonth->net, $lastMonth->net))
                ->chart($netSpark),

            Stat::make('ΦΠΑ μήνα (εκροών)', $this->eur($thisMonth->vat))
                ->description('Μικτά: '.$this->eur($thisMonth->gross).' • μόνο ΦΠΑ εκροών')
                ->descriptionIcon('heroicon-m-receipt-percent')
                ->color('warning')
                ->chart($vatSpark),

            Stat::make('Ανεξόφλητα (πιστωτικά)', $this->eur($outstanding))
                ->description('Υπόλοιπο πελατών με πίστωση — δες ποιοι')
                ->descriptionIcon('heroicon-m-banknotes')
                ->color($outstanding > 0 ? 'danger' : 'success')
                // Drill into the customers who owe (the «Χρεωστικοί» option of
                // the balance filter on the Customers list). getUrl() carries the
                // current tenant slug in the path automatically; TableFilterUrl
                // knows the query-string key (`filters`, NOT `tableFilters` — the
                // old key bound to nothing and this card landed unfiltered; the
                // `sort` below was added as a workaround for that misdiagnosis).
                // The sort stays because it is what the operator wants here:
                // biggest debtors first.
                ->url(CustomerResource::getUrl('index', TableFilterUrl::with(
                    ['balance_status' => ['value' => 'debtor']],
                    ['sort' => 'outstanding_balance:desc'],
                )))
                ->extraAttributes(['class' => 'cursor-pointer']),

            $this->invoiceCountStat($tenant, $metrics, $thisMonth->count),
        ];

        // MON-5: πρόχειρα (προτιμολόγια) — kept OUT of the money totals above, so
        // show them separately whenever any exist (count + value), clickable into
        // the invoices list filtered to drafts. Zero drafts → no tile (no noise).
        $drafts = $metrics->draftsPipeline();
        if ($drafts['count'] > 0) {
            $stats[] = Stat::make('Πρόχειρα (προτιμολόγια)', (string) $drafts['count'])
                ->description('Αξία (μικτά): '.$this->eur($drafts['gross']).' • δεν μετρούν στα έσοδα')
                ->descriptionIcon('heroicon-m-document')
                ->color('gray')
                ->url(InvoiceResource::getUrl('index', TableFilterUrl::with(
                    ['local_status' => ['value' => 'draft']],
                )))
                ->extraAttributes(['class' => 'cursor-pointer']);
        }

        return $stats;
    }

    private function invoiceCountStat(Company $tenant, DashboardMetrics $metrics, int $count): Stat
    {
        $stat = Stat::make('Παραστατικά μήνα', (string) $count)
            ->descriptionIcon('heroicon-m-document-text')
            ->color('primary');

        // The "not yet filed at myDATA" backlog is only meaningful for
        // Greek myDATA tenants. For EE / off tenants the same column
        // would just be "drafts", which isn't actionable here.
        if ($tenant->einvoice_provider === 'gr-mydata') {
            $unfiled = $metrics->unfiledCount();
            $stat->description($unfiled > 0
                ? "Εκκρεμούν στο myDATA: {$unfiled}"
                : 'Όλα υποβλήθηκαν στο myDATA')
                ->color($unfiled > 0 ? 'warning' : 'success');
        } else {
            $stat->description('Εκδόσεις τρέχοντος μήνα');
        }

        return $stat;
    }
}
