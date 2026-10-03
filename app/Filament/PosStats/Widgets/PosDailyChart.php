<?php

namespace App\Filament\PosStats\Widgets;

use App\Filament\PosStats\Widgets\Concerns\ReadsPosFilters;
use App\Filament\Reports\Widgets\Concerns\FormatsReportChart;
use App\Support\Dashboard\ReportPalette;
use Carbon\CarbonImmutable;
use Filament\Widgets\ChartWidget;

/** Net per day of the chosen month (bars) vs the same month of the comparison year (line). */
class PosDailyChart extends ChartWidget
{
    use FormatsReportChart;
    use ReadsPosFilters;

    /** No auto-refresh (Filament's default is every 5s — each poll re-reads up to two years of receipts). */
    protected ?string $pollingInterval = null;

    protected static ?int $sort = 2;

    protected int|string|array $columnSpan = 'full';

    protected ?string $maxHeight = '280px';

    public function getHeading(): ?string
    {
        $m = CarbonImmutable::create($this->year(), $this->month(), 1)->locale('el');

        return 'Ανά ημέρα — '.$m->translatedFormat('F Y').' vs '.$m->setYear($this->compareYear())->translatedFormat('F Y');
    }

    protected function getData(): array
    {
        $stats = $this->stats();
        if ($stats === null) {
            return ['datasets' => [], 'labels' => []];
        }
        $now = $stats->dailyNet($this->year(), $this->month());
        $before = $stats->dailyNet($this->compareYear(), $this->month());
        $days = max(count($now), count($before));

        return [
            'datasets' => [
                ['type' => 'bar', 'label' => (string) $this->year(), 'data' => $now, 'backgroundColor' => ReportPalette::PRIMARY],
                ['type' => 'line', 'label' => (string) $this->compareYear(), 'data' => $before, 'borderColor' => ReportPalette::MUTED, 'fill' => false],
            ],
            'labels' => array_map('strval', range(1, $days)),
        ];
    }

    protected function getType(): string
    {
        return 'bar';
    }
}
