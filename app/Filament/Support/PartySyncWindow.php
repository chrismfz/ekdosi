<?php

namespace App\Filament\Support;

use Carbon\Carbon;
use Carbon\CarbonInterface;
use Filament\Forms\Components\Component;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Schemas\Components\Utilities\Get;

/**
 * Shared date-window picker for the «Συγχρονισμός από myDATA» party-sync actions
 * (customers ← sales counterparts, suppliers ← expense issuers). A rolling
 * LOOKBACK preset (3 / 12 / 24 μήνες) — the wider the window, the more ΑΦΜ
 * συναλλαγών it captures — defaulting to 12 months so a single run grabs a year
 * of trading partners (the old 1-month default was far too narrow). «Προσαρμογή»
 * reveals explicit from/to.
 */
class PartySyncWindow
{
    /** @return array<int, Component> */
    public static function schema(): array
    {
        return [
            Select::make('window')
                ->label('Διάστημα')
                ->options([
                    '3' => 'Τελευταίοι 3 μήνες',
                    '12' => 'Τελευταίοι 12 μήνες',
                    '24' => 'Τελευταίοι 24 μήνες',
                    'custom' => 'Προσαρμογή…',
                ])
                ->default('12')
                ->selectablePlaceholder(false)
                ->live()
                ->helperText('Όσο μεγαλύτερο το διάστημα, τόσα περισσότερα ΑΦΜ συναλλαγών πιάνει.'),
            DatePicker::make('from')
                ->label('Από')
                ->visible(fn (Get $get): bool => $get('window') === 'custom')
                ->required(fn (Get $get): bool => $get('window') === 'custom')
                ->default(now()->subYear()),
            DatePicker::make('to')
                ->label('Έως')
                ->visible(fn (Get $get): bool => $get('window') === 'custom')
                ->required(fn (Get $get): bool => $get('window') === 'custom')
                ->default(now()),
        ];
    }

    /**
     * Resolve the submitted preset/custom into a concrete [from, to] window.
     *
     * @param  array<string, mixed>  $data
     * @return array{0: CarbonInterface, 1: CarbonInterface}
     */
    public static function resolve(array $data): array
    {
        $to = now();

        return match ($data['window'] ?? '12') {
            '3' => [now()->subMonths(3)->startOfDay(), $to],
            '24' => [now()->subMonths(24)->startOfDay(), $to],
            'custom' => [Carbon::parse($data['from'])->startOfDay(), Carbon::parse($data['to'])->endOfDay()],
            default => [now()->subMonths(12)->startOfDay(), $to], // 12
        };
    }
}
