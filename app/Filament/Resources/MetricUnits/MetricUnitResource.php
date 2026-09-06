<?php

namespace App\Filament\Resources\MetricUnits;

use App\Filament\Clusters\SettingsCluster;
use App\Filament\Resources\MetricUnits\Pages\CreateMetricUnit;
use App\Filament\Resources\MetricUnits\Pages\EditMetricUnit;
use App\Filament\Resources\MetricUnits\Pages\ListMetricUnits;
use App\Filament\Resources\MetricUnits\Schemas\MetricUnitForm;
use App\Filament\Resources\MetricUnits\Tables\MetricUnitsTable;
use App\Filament\Support\GuardedDeleteAction;
use App\Models\MetricUnit;
use App\Models\Product;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletingScope;

class MetricUnitResource extends Resource
{
    protected static ?string $model = MetricUnit::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedScale;

    protected static ?string $cluster = SettingsCluster::class;

    protected static ?int $navigationSort = 70;

    protected static ?string $modelLabel = 'Μονάδα μέτρησης';

    protected static ?string $pluralModelLabel = 'Μονάδες μέτρησης';

    // Verbatim nav label — else Filament title-cases the plural.
    protected static ?string $navigationLabel = 'Μονάδες μέτρησης';

    protected static ?string $recordTitleAttribute = 'name';

    /**
     * SET-2: dependent counts blocking deletion (single + bulk + force). One source.
     *
     * @return array<string, int>
     */
    public static function dependents(Model $record): array
    {
        return [
            'προϊόντα' => GuardedDeleteAction::count(Product::class, 'metric_unit_id', $record->id),
        ];
    }

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
