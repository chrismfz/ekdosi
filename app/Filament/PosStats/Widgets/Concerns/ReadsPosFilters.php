<?php

namespace App\Filament\PosStats\Widgets\Concerns;

use App\Models\Company;
use App\Services\Pos\PosStats;
use App\Support\Dashboard\ReportFilters;
use Filament\Facades\Filament;
use Filament\Widgets\Concerns\InteractsWithPageFilters;

/** The «Ταμεία — στατιστικά» page filters (year / month / comparison year) + the stats service. */
trait ReadsPosFilters
{
    use InteractsWithPageFilters;

    protected function stats(): ?PosStats
    {
        $tenant = Filament::getTenant();

        if (! $tenant instanceof Company) {
            return null;
        }
        // One instance per request for the whole page (its per-window memo is shared
        // by the widgets rendered together) — a scoped binding, dropped between requests.
        $key = 'pos-stats.'.$tenant->getKey();
        if (! app()->bound($key)) {
            app()->scoped($key, fn () => new PosStats($tenant));
        }

        return app($key);
    }

    protected function year(): int
    {
        return ReportFilters::year($this->pageFilters);
    }

    protected function compareYear(): int
    {
        return ReportFilters::compareYear($this->pageFilters);
    }

    protected function month(): int
    {
        $m = (int) ($this->pageFilters['month'] ?? 0);

        return $m >= 1 && $m <= 12 ? $m : (int) now()->month;
    }
}
