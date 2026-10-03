<?php

namespace App\Filament\PosStats\Widgets;

use App\Filament\PosStats\Widgets\Concerns\ReadsPosFilters;
use Carbon\CarbonImmutable;
use Filament\Widgets\Widget;

/** Busy hours of the chosen month: receipts per weekday × hour, shaded — for staffing. */
class PosBusyHours extends Widget
{
    use ReadsPosFilters;

    protected static ?int $sort = 6;

    protected int|string|array $columnSpan = 'full';

    protected string $view = 'filament.pos-stats.busy-hours';

    /** @return array{title: string, grid: array<string, mixed>, max: int} */
    protected function getViewData(): array
    {
        $start = CarbonImmutable::create($this->year(), $this->month(), 1);
        $grid = $this->stats()?->weekdayHours($start, $start->endOfMonth()) ?? ['receipts' => [], 'net' => [], 'hours' => []];
        $max = 0;
        foreach ($grid['receipts'] as $hours) {
            $max = max($max, ...array_values($hours));
        }

        return [
            'title' => 'Ώρες αιχμής — '.$start->locale('el')->translatedFormat('F Y').' (αποδείξεις ανά ημέρα × ώρα)',
            'grid' => $grid,
            'max' => $max,
        ];
    }
}
