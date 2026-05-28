<?php

namespace App\Filament\Resources\Customers\Widgets;

use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

/**
 * Aging buckets for the outstanding receivable (0-30 / 31-60 / 61-90 /
 * 90+ days), FIFO-allocated by CustomerLedgerBuilder. When the customer
 * is settled it collapses to a single reassuring "no debt" stat instead
 * of four zero tiles.
 */
class CustomerLedgerAging extends StatsOverviewWidget
{
    /** @var array<string, mixed> */
    public array $ledgerAging = [];

    /** @var array<string, mixed> */
    public array $ledgerStats = [];

    protected ?string $heading = 'Ανάλυση ανεξόφλητων κατά ηλικία';

    protected function getColumns(): int
    {
        return 4;
    }

    protected function getStats(): array
    {
        $fmt = fn ($v): string => number_format((float) ($v ?? 0), 2, ',', '.').' €';
        $a = $this->ledgerAging;
        $balance = (float) ($this->ledgerStats['balance'] ?? 0);

        if ($balance <= 0) {
            return [
                Stat::make('Οφειλές', 'Καμία')
                    ->description('Ο πελάτης δεν έχει ανεξόφλητο υπόλοιπο')
                    ->descriptionIcon('heroicon-m-check-circle')
                    ->color('success'),
            ];
        }

        return [
            Stat::make('0–30 ημ.', $fmt($a['bucket_0_30'] ?? 0))
                ->descriptionIcon('heroicon-m-clock')
                ->color('success'),
            Stat::make('31–60 ημ.', $fmt($a['bucket_31_60'] ?? 0))
                ->descriptionIcon('heroicon-m-clock')
                ->color('warning'),
            Stat::make('61–90 ημ.', $fmt($a['bucket_61_90'] ?? 0))
                ->descriptionIcon('heroicon-m-clock')
                ->color('warning'),
            Stat::make('90+ ημ.', $fmt($a['bucket_90_plus'] ?? 0))
                ->descriptionIcon('heroicon-m-exclamation-triangle')
                ->color('danger'),
        ];
    }
}
