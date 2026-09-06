<?php

namespace App\Filament\Resources\ProductCategories;

use App\Filament\Clusters\SettingsCluster;
use App\Filament\Resources\ProductCategories\Pages\CreateProductCategory;
use App\Filament\Resources\ProductCategories\Pages\EditProductCategory;
use App\Filament\Resources\ProductCategories\Pages\ListProductCategories;
use App\Filament\Resources\ProductCategories\Schemas\ProductCategoryForm;
use App\Filament\Resources\ProductCategories\Tables\ProductCategoriesTable;
use App\Filament\Support\GuardedDeleteAction;
use App\Models\Product;
use App\Models\ProductCategory;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletingScope;

class ProductCategoryResource extends Resource
{
    protected static ?string $model = ProductCategory::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedTag;

    protected static ?string $cluster = SettingsCluster::class;

    protected static ?int $navigationSort = 50;

    protected static ?string $modelLabel = 'Κατηγορία προϊόντος';

    protected static ?string $pluralModelLabel = 'Κατηγορίες προϊόντων';

    // Verbatim nav label — else Filament title-cases the plural.
    protected static ?string $navigationLabel = 'Κατηγορίες προϊόντων';

    protected static ?string $recordTitleAttribute = 'description_short';

    /**
     * SET-2: dependent counts blocking deletion (single + bulk + force). One source.
     *
     * @return array<string, int>
     */
    public static function dependents(Model $record): array
    {
        return [
            'προϊόντα' => GuardedDeleteAction::count(Product::class, 'product_category_id', $record->id),
        ];
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->withoutGlobalScopes([SoftDeletingScope::class]);
    }

    public static function form(Schema $schema): Schema
    {
        return ProductCategoryForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return ProductCategoriesTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListProductCategories::route('/'),
            'create' => CreateProductCategory::route('/create'),
            'edit' => EditProductCategory::route('/{record}/edit'),
        ];
    }
}
