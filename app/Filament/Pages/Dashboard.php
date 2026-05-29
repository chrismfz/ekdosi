<?php

namespace App\Filament\Pages;

use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Pages\Dashboard as BaseDashboard;
use Filament\Pages\Dashboard\Concerns\HasFiltersForm;
use Filament\Schemas\Schema;

/**
 * Greek-titled dashboard. Widgets are auto-discovered from
 * app/Filament/Widgets (see AdminPanelProvider::discoverWidgets) and
 * ordered by each widget's $sort.
 *
 * The period filter (HasFiltersForm) drives ONLY the filter-aware
 * widgets — the two charts (which read it via
 * App\Support\Dashboard\PeriodFilter). The fixed headline / comparison
 * cards are intentionally period-independent, so the filter's helper
 * text says so.
 */
class Dashboard extends BaseDashboard
{
    use HasFiltersForm;

    public function getTitle(): string
    {
        return 'Πίνακας ελέγχου';
    }

    public static function getNavigationLabel(): string
    {
        return 'Πίνακας ελέγχου';
    }

    public function filtersForm(Schema $schema): Schema
    {
        return $schema->components([
            Select::make('period')
                ->label('Περίοδος')
                ->options([
                    'this_month' => 'Τρέχων μήνας',
                    'last_month' => 'Προηγ. μήνας',
                    'quarter'    => 'Τρέχον τρίμηνο',
                    'year'       => 'Τρέχον έτος',
                    'custom'     => 'Προσαρμοσμένο…',
                ])
                ->default('this_month')
                ->selectablePlaceholder(false)
                ->live()
                ->helperText('Επηρεάζει τα γραφήματα και τις κάρτες «περιόδου» — όχι τις σταθερές κάρτες πάνω.'),

            DatePicker::make('from')
                ->label('Από')
                ->native(false)
                ->visible(fn (callable $get): bool => $get('period') === 'custom'),

            DatePicker::make('to')
                ->label('Έως')
                ->native(false)
                ->visible(fn (callable $get): bool => $get('period') === 'custom'),
        ]);
    }
}
