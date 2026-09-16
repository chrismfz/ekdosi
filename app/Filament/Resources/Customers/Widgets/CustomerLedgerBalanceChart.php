<?php

namespace App\Filament\Resources\Customers\Widgets;

use Filament\Widgets\ChartWidget;

/**
 * Year-end balance trend. Only embedded by the page when there are ≥2
 * years of history (a single point is not a trend). Receives the yearly
 * block via @livewire prop — no DB access of its own.
 */
class CustomerLedgerBalanceChart extends ChartWidget
{
    /** @var array<int, array<string, mixed>> */
    public array $ledgerYearly = [];

    protected ?string $heading = 'Υπόλοιπο τέλους έτους';

    protected int | string | array $columnSpan = 'full';

    // Compact (dashboard-style miniature): capped height, laid out beside the
    // revenue chart below the ledger table instead of towering above it.
    protected ?string $maxHeight = '240px';

    protected function getType(): string
    {
        return 'line';
    }

    protected function getData(): array
    {
        // Yearly is stored newest-first; reverse to plot oldest → newest.
        $rows = array_reverse($this->ledgerYearly);

        return [
            'datasets' => [
                [
                    'label' => 'Υπόλοιπο τέλους έτους',
                    'data' => array_map(fn ($r): float => (float) ($r['year_end_balance'] ?? 0), $rows),
                    'borderColor' => '#ef4444',
                    'backgroundColor' => 'rgba(239, 68, 68, 0.12)',
                    'fill' => true,
                    'tension' => 0.3,
                ],
            ],
            'labels' => array_map(fn ($r): string => (string) ($r['year'] ?? ''), $rows),
        ];
    }
}
