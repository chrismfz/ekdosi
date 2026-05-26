<?php

namespace App\Filament\Resources\DistributionAims;

use App\Filament\Resources\DistributionAims\Pages\CreateDistributionAim;
use App\Filament\Resources\DistributionAims\Pages\EditDistributionAim;
use App\Filament\Resources\DistributionAims\Pages\ListDistributionAims;
use App\Filament\Resources\DistributionAims\Schemas\DistributionAimForm;
use App\Filament\Resources\DistributionAims\Tables\DistributionAimsTable;
use App\Models\DistributionAim;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use UnitEnum;

class DistributionAimResource extends Resource
{
    protected static ?string $model = DistributionAim::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedPaperAirplane;

    protected static string|UnitEnum|null $navigationGroup = 'Setup';

    protected static ?int $navigationSort = 40;

    protected static ?string $recordTitleAttribute = 'description';

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->withoutGlobalScopes([SoftDeletingScope::class]);
    }

    public static function form(Schema $schema): Schema
    {
        return DistributionAimForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return DistributionAimsTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListDistributionAims::route('/'),
            'create' => CreateDistributionAim::route('/create'),
            'edit' => EditDistributionAim::route('/{record}/edit'),
        ];
    }
}
