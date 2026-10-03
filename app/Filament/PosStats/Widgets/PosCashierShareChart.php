<?php

namespace App\Filament\PosStats\Widgets;

use App\Filament\PosStats\Widgets\Concerns\ReadsPosFilters;
use App\Models\User;
use App\Support\Dashboard\ReportPalette;
use Carbon\CarbonImmutable;
use Filament\Widgets\ChartWidget;

/** Who rang the chosen month's turnover — share per cashier. */
class PosCashierShareChart extends ChartWidget
{
    use ReadsPosFilters;

    /** No auto-refresh (Filament's default is every 5s — each poll re-reads up to two years of receipts). */
    protected ?string $pollingInterval = null;

    protected static ?int $sort = 5;

    public function getHeading(): ?string
    {
        return 'Ανά ταμία — '.CarbonImmutable::create($this->year(), $this->month(), 1)->locale('el')->translatedFormat('F Y');
    }

    protected function getData(): array
    {
        $stats = $this->stats();
        if ($stats === null) {
            return ['datasets' => [], 'labels' => []];
        }
        $start = CarbonImmutable::create($this->year(), $this->month(), 1);
        $by = $stats->netByCashier($start, $start->endOfMonth());
        $names = User::query()->whereKey(array_filter(array_keys($by), 'is_int'))->pluck('name', 'id');
        $palette = [ReportPalette::PRIMARY, ReportPalette::POSITIVE, ReportPalette::WARNING, ReportPalette::FORECAST, ReportPalette::MUTED, '#8b5cf6', '#ec4899', '#14b8a6'];

        return [
            'datasets' => [[
                'data' => array_values($by),
                'backgroundColor' => array_slice(array_merge($palette, $palette), 0, count($by)),
            ]],
            'labels' => array_map(fn ($id) => $id === '' ? '— άγνωστος —' : ($names[$id] ?? '#'.$id), array_keys($by)),
        ];
    }

    protected function getType(): string
    {
        return 'doughnut';
    }
}
