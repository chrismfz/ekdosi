<?php

namespace App\Filament\PosStats\Widgets;

use App\Filament\PosStats\Widgets\Concerns\ReadsPosFilters;
use App\Filament\Reports\Widgets\Concerns\FormatsReportChart;
use App\Support\Dashboard\ReportPalette;
use Filament\Widgets\ChartWidget;

/** Running total through the year vs the comparison year — «είμαστε μπροστά ή πίσω;». */
class PosCumulativeChart extends ChartWidget
{
    use FormatsReportChart;
    use ReadsPosFilters;

    /** No auto-refresh (Filament's default is every 5s — each poll re-reads up to two years of receipts). */
    protected ?string $pollingInterval = null;

    protected static ?int $sort = 4;

    public function getHeading(): ?string
    {
        return $this->year().' vs '.$this->compareYear().' (σωρευτικά)';
    }

    protected function getData(): array
    {
        $stats = $this->stats();
        if ($stats === null) {
            return ['datasets' => [], 'labels' => []];
        }

        return [
            'datasets' => [
                ['label' => (string) $this->year(), 'data' => $stats->cumulativeMonthlyNet($this->year()), 'borderColor' => ReportPalette::PRIMARY, 'fill' => false],
                ['label' => (string) $this->compareYear(), 'data' => $stats->cumulativeMonthlyNet($this->compareYear()), 'borderColor' => ReportPalette::MUTED, 'fill' => false],
            ],
            'labels' => PosMonthlyChart::MONTHS,
        ];
    }

    protected function getType(): string
    {
        return 'line';
    }
}
