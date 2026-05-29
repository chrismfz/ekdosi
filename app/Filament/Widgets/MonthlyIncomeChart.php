<?php

namespace App\Filament\Widgets;

use App\Models\Company;
use App\Services\Dashboard\DashboardMetrics;
use Filament\Facades\Filament;
use Filament\Widgets\ChartWidget;

/**
 * Net income + output VAT per month over the last 12 months, as a
 * grouped bar chart. The dense series (zero-filled empty months) keeps
 * the x-axis continuous.
 */
class MonthlyIncomeChart extends ChartWidget
{
    protected static ?int $sort = 6;

    protected ?string $heading = 'Έσοδα ανά μήνα (12 μήνες)';

    protected function getData(): array
    {
        $tenant = Filament::getTenant();
        if (! $tenant instanceof Company) {
            return ['datasets' => [], 'labels' => []];
        }

        $rows = (new DashboardMetrics($tenant))->monthlyIncome(12);

        return [
            'datasets' => [
                [
                    'label'           => 'Καθαρά',
                    'data'            => array_column($rows, 'net'),
                    'backgroundColor' => '#3b82f6',
                ],
                [
                    'label'           => 'ΦΠΑ εκροών',
                    'data'            => array_column($rows, 'vat'),
                    'backgroundColor' => '#f59e0b',
                ],
            ],
            'labels' => array_column($rows, 'label'),
        ];
    }

    protected function getType(): string
    {
        return 'bar';
    }
}
