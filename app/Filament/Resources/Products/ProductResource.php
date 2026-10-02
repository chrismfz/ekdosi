<?php

namespace App\Filament\Resources\Products;

use App\Filament\Resources\Products\Pages\CreateProduct;
use App\Filament\Resources\Products\Pages\EditProduct;
use App\Filament\Resources\Products\Pages\ListProducts;
use App\Filament\Resources\Products\RelationManagers\PriceTiersRelationManager;
use App\Filament\Resources\Products\RelationManagers\StockMovementsRelationManager;
use App\Filament\Resources\Products\RelationManagers\VariantsRelationManager;
use App\Filament\Resources\Products\Schemas\ProductForm;
use App\Filament\Resources\Products\Tables\ProductsTable;
use App\Models\Product;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use UnitEnum;

class ProductResource extends Resource
{
    protected static ?string $model = Product::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCube;

    protected static string|UnitEnum|null $navigationGroup = 'Είδη & Προμήθειες';

    protected static ?int $navigationSort = 10;

    protected static ?string $modelLabel = 'Προϊόν';

    protected static ?string $pluralModelLabel = 'Προϊόντα';

    protected static ?string $recordTitleAttribute = 'description_short';

    /**
     * A variable parent can't be (soft-)deleted while live variants hang off it —
     * they'd stay sellable under a vanished grouping. Delete/deactivate them first.
     *
     * @return array<string, int>
     */
    public static function dependents(Model $record): array
    {
        return [
            'ενεργές παραλλαγές' => $record instanceof Product ? $record->variants()->count() : 0,
        ];
    }

    /**
     * Force-delete: any variant row, trashed or not (parent_product_id is a
     * restrictOnDelete FK).
     *
     * @return array<string, int>
     */
    public static function forceDependents(Model $record): array
    {
        return [
            'παραλλαγές (και διαγραμμένες)' => $record instanceof Product ? $record->variants()->withTrashed()->count() : 0,
        ];
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->withoutGlobalScopes([SoftDeletingScope::class]);
    }

    public static function form(Schema $schema): Schema
    {
        return ProductForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return ProductsTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [
            VariantsRelationManager::class,
            PriceTiersRelationManager::class,
            StockMovementsRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListProducts::route('/'),
            'create' => CreateProduct::route('/create'),
            'edit' => EditProduct::route('/{record}/edit'),
        ];
    }
}
