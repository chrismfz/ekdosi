<?php

namespace App\Filament\Widgets\Concerns;

/**
 * Greek money + trend formatting shared by the stat widgets. Money is
 * rendered Greek-style (1.234,56 €) — comma decimal, dot thousands.
 */
trait FormatsDashboardValues
{
    protected function eur(float $value): string
    {
        return number_format($value, 2, ',', '.').' €';
    }

    /**
     * Percent-change description vs a baseline, Greek-worded. Handles
     * the zero-baseline case (can't divide) explicitly.
     */
    protected function trendText(float $current, float $previous): string
    {
        if (abs($previous) < 0.005) {
            return $current > 0 ? 'νέα έσοδα' : '—';
        }
        $pct = ($current - $previous) / abs($previous) * 100;
        $arrow = $pct >= 0 ? '▲' : '▼';

        return $arrow.' '.number_format(abs($pct), 1, ',', '.').'%';
    }

    protected function trendIcon(float $current, float $previous): string
    {
        return $current >= $previous
            ? 'heroicon-m-arrow-trending-up'
            : 'heroicon-m-arrow-trending-down';
    }

    protected function trendColor(float $current, float $previous): string
    {
        return $current >= $previous ? 'success' : 'danger';
    }
}
