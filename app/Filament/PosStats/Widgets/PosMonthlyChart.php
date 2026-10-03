<?php

namespace App\Filament\PosStats\Widgets;

use App\Filament\PosStats\Widgets\Concerns\ReadsPosFilters;
use App\Filament\Reports\Widgets\Concerns\FormatsReportChart;
use App\Support\Dashboard\ReportPalette;
use Filament\Widgets\ChartWidget;

/** Net per month: the year vs the comparison year, side by side. */
class PosMonthlyChart extends ChartWidget
{
    use FormatsReportChart;
    use ReadsPosFilters;

    /** No auto-refresh (Filament's default is every 5s — each poll re-reads up to two years of receipts). */
    protected ?string $pollingInterval = null;

    protected static ?int $sort = 3;

    public const MONTHS = ['Ιαν', 'Φεβ', 'Μάρ', 'Απρ', 'Μάι', 'Ιούν', 'Ιούλ', 'Αύγ', 'Σεπ', 'Οκτ', 'Νοέ', 'Δεκ'];

    public function getHeading(): ?string
    {
        return 'Ανά μήνα — '.$this->year().' vs '.$this->compareYear();
    }

    protected function getData(): array
    {
        $stats = $this->stats();
        if ($stats === null) {
            return ['datasets' => [], 'labels' => []];
        }

        return [
            'datasets' => [
                ['label' => (string) $this->year(), 'data' => $stats->monthlyNet($this->year()), 'backgroundColor' => ReportPalette::PRIMARY],
                ['label' => (string) $this->compareYear(), 'data' => $stats->monthlyNet($this->compareYear()), 'backgroundColor' => ReportPalette::MUTED],
            ],
            'labels' => self::MONTHS,
        ];
    }

    protected function getType(): string
    {
        return 'bar';
    }
}
