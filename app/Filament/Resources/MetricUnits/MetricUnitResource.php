<?php

namespace App\Filament\Resources\MetricUnits;

use App\Filament\Resources\MetricUnits\Pages\CreateMetricUnit;
use App\Filament\Resources\MetricUnits\Pages\EditMetricUnit;
use App\Filament\Resources\MetricUnits\Pages\ListMetricUnits;
use App\Filament\Resources\MetricUnits\Schemas\MetricUnitForm;
use App\Filament\Resources\MetricUnits\Tables\MetricUnitsTable;
use App\Models\MetricUnit;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use UnitEnum;

class MetricUnitResource extends Resource
{
    protected static ?string $model = MetricUnit::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedScale;

    protected static string|UnitEnum|null $navigationGroup = 'Setup';

    protected static ?int $navigationSort = 70;

    protected static ?string $recordTitleAttribute = 'name';

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->withoutGlobalScopes([SoftDeletingScope::class]);
    }

    public static function form(Schema $schema): Schema
    {
        return MetricUnitForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return MetricUnitsTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListMetricUnits::route('/'),
            'create' => CreateMetricUnit::route('/create'),
            'edit' => EditMetricUnit::route('/{record}/edit'),
        ];
    }
}
