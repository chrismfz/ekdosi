<?php

namespace App\Filament\Resources\VatCategories;

use App\Filament\Resources\VatCategories\Pages\CreateVatCategory;
use App\Filament\Resources\VatCategories\Pages\EditVatCategory;
use App\Filament\Resources\VatCategories\Pages\ListVatCategories;
use App\Filament\Resources\VatCategories\Schemas\VatCategoryForm;
use App\Filament\Resources\VatCategories\Tables\VatCategoriesTable;
use App\Models\VatCategory;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use UnitEnum;

class VatCategoryResource extends Resource
{
    protected static ?string $model = VatCategory::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedReceiptPercent;

    protected static string|UnitEnum|null $navigationGroup = 'Setup';

    protected static ?int $navigationSort = 60;

    protected static ?string $recordTitleAttribute = 'description';

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->withoutGlobalScopes([SoftDeletingScope::class]);
    }

    public static function form(Schema $schema): Schema
    {
        return VatCategoryForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return VatCategoriesTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListVatCategories::route('/'),
            'create' => CreateVatCategory::route('/create'),
            'edit' => EditVatCategory::route('/{record}/edit'),
        ];
    }
}
