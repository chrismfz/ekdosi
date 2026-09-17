<?php

namespace App\Filament\Reports\Widgets\Concerns;

use Filament\Support\RawJs;

/**
 * Shared Chart.js options for the «Αναφορές» money charts: format the y-axis
 * ticks and the tooltip value as euros (Greek locale, 1.234 € on the axis,
 * 1.234,56 € in the tooltip) instead of the raw numbers the charts showed
 * before. Every report chart plots money, so one shared definition keeps them
 * consistent; the formatting is client-side (Intl.NumberFormat) so it needs no
 * per-point server work and adapts to light/dark automatically.
 */
trait FormatsReportChart
{
    protected function getOptions(): RawJs
    {
        return RawJs::make(<<<'JS'
        {
            scales: {
                y: {
                    ticks: {
                        callback: (value) => new Intl.NumberFormat('el-GR', {
                            style: 'currency', currency: 'EUR', maximumFractionDigits: 0,
                        }).format(value),
                    },
                },
            },
            plugins: {
                tooltip: {
                    callbacks: {
                        label: (ctx) => {
                            const v = ctx.parsed.y ?? ctx.parsed;
                            const money = new Intl.NumberFormat('el-GR', {
                                style: 'currency', currency: 'EUR',
                            }).format(v);
                            return ctx.dataset.label ? `${ctx.dataset.label}: ${money}` : money;
                        },
                    },
                },
            },
        }
        JS);
    }
}
