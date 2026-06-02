<?php

namespace App\Filament\Pages\Concerns;

use Carbon\Carbon;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Schemas\Components\Utilities\Get;

/**
 * Shared date-window picker for the myDATA consoles: a preset selector
 * (Μήνας / Τρίμηνο / Προηγούμενο τρίμηνο / Έτος / Προσαρμογή) over CALENDAR
 * (= φορολογικά) boundaries, with custom from/to revealed only for «Προσαρμογή».
 * One home so both consoles (έσοδα / έξοδα) stay identical and a preset
 * definition is defined once.
 */
trait ResolvesReconcileWindow
{
    /** @return array<int, \Filament\Forms\Components\Component> */
    protected function windowSchema(): array
    {
        return [
            Select::make('preset')
                ->label('Διάστημα')
                ->options([
                    'month' => 'Τρέχων μήνας',
                    'quarter' => 'Τρέχον τρίμηνο',
                    'prev_quarter' => 'Προηγούμενο τρίμηνο',
                    'year' => 'Τρέχον έτος',
                    'custom' => 'Προσαρμογή…',
                ])
                ->default('quarter')
                ->selectablePlaceholder(false)
                ->live(),

            DatePicker::make('from')
                ->label('Από')
                ->visible(fn (Get $get): bool => $get('preset') === 'custom')
                ->required(fn (Get $get): bool => $get('preset') === 'custom')
                ->default(now()->startOfQuarter()),

            DatePicker::make('to')
                ->label('Έως')
                ->visible(fn (Get $get): bool => $get('preset') === 'custom')
                ->required(fn (Get $get): bool => $get('preset') === 'custom')
                ->default(now()),
        ];
    }

    /**
     * Resolve the submitted preset/custom into a concrete [from, to] window
     * (Y-m-d). Calendar boundaries; current periods run up to today, the
     * previous quarter is the full previous calendar quarter.
     *
     * @param  array<string,mixed>  $data
     * @return array{0: string, 1: string}
     */
    protected function resolveWindow(array $data): array
    {
        $preset = $data['preset'] ?? 'quarter';
        $now = now();

        return match ($preset) {
            'custom' => [
                Carbon::parse($data['from'])->toDateString(),
                Carbon::parse($data['to'])->toDateString(),
            ],
            'month' => [$now->copy()->startOfMonth()->toDateString(), $now->toDateString()],
            'year' => [$now->copy()->startOfYear()->toDateString(), $now->toDateString()],
            'prev_quarter' => [
                $now->copy()->subQuarter()->startOfQuarter()->toDateString(),
                $now->copy()->subQuarter()->endOfQuarter()->toDateString(),
            ],
            default => [$now->copy()->startOfQuarter()->toDateString(), $now->toDateString()], // quarter
        };
    }
}
