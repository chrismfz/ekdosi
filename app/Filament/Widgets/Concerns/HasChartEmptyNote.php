<?php

namespace App\Filament\Widgets\Concerns;

/**
 * Makes a Filament `ChartWidget` render its BUILT-IN empty state (heading + icon,
 * with the blank `<canvas>` hidden) when there's nothing to plot — instead of the
 * default `isEmpty()`, which tests the whole `getData()` array and so is always
 * `false` for a widget that returns the usual `['datasets' => [...], 'labels' => []]`
 * shape even with zero data points.
 *
 * The widget just sets `$emptyStateHeading` (and optionally `$emptyStateIcon`),
 * both provided by ChartWidget's HasEmptyState trait.
 *
 * Assumes a SINGLE primary dataset (datasets[0]) — the case for every widget that
 * uses it today. A future multi-dataset chart should check whether ANY dataset has
 * data instead of adopting this verbatim.
 */
trait HasChartEmptyNote
{
    public function isEmpty(): bool
    {
        // getCachedData() memoises the getData() result the blade also reads, so
        // this doesn't run getData() a second time per render.
        $datasets = $this->getCachedData()['datasets'] ?? [];

        return $datasets === [] || ($datasets[0]['data'] ?? []) === [];
    }
}
