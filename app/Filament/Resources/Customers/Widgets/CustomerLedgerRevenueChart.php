<?php

namespace App\Filament\Resources\Customers\Widgets;

use Filament\Widgets\ChartWidget;

/**
 * Per-year net revenue vs payments for this customer — the Καρτέλα
 * answer to the dashboard's year-comparison chart. Net (billed) next to
 * payments (collected) per year makes the gap between invoiced and
 * cashed-in visible at a glance. Only embedded when ≥2 years of history
 * exist (a single year isn't a comparison). Receives the yearly block
 * via @livewire prop — no DB access of its own.
 */
class CustomerLedgerRevenueChart extends ChartWidget
{
    /** @var array<int, array<string, mixed>> */
    public array $ledgerYearly = [];

    protected ?string $heading = 'Καθαρά έσοδα & πληρωμές ανά έτος';

    protected int | string | array $columnSpan = 'full';

    // Compact (dashboard-style miniature): capped height so the chart sits below
    // the ledger without dominating the page. The blade lays two of these side-by-side.
    protected ?string $maxHeight = '240px';

    protected function getType(): string
    {
        return 'bar';
    }

    protected function getData(): array
    {
        // Yearly is stored newest-first; reverse to plot oldest → newest.
        $rows = array_reverse($this->ledgerYearly);

        return [
            'datasets' => [
                [
                    'label' => 'Καθαρή αξία',
                    'data' => array_map(fn ($r): float => (float) ($r['net'] ?? 0), $rows),
                    'backgroundColor' => '#3b82f6',
                ],
                [
                    'label' => 'Πληρωμές',
                    'data' => array_map(fn ($r): float => (float) ($r['paid'] ?? 0), $rows),
                    'backgroundColor' => '#10b981',
                ],
            ],
            'labels' => array_map(fn ($r): string => (string) ($r['year'] ?? ''), $rows),
        ];
    }
}
