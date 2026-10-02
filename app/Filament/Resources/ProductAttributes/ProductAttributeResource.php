<?php

namespace App\Filament\Resources\ProductAttributes;

use App\Filament\Resources\ProductAttributes\Pages\CreateProductAttribute;
use App\Filament\Resources\ProductAttributes\Pages\EditProductAttribute;
use App\Filament\Resources\ProductAttributes\Pages\ListProductAttributes;
use App\Filament\Resources\ProductAttributes\Schemas\ProductAttributeForm;
use App\Filament\Resources\ProductAttributes\Tables\ProductAttributesTable;
use App\Models\ProductAttribute;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use UnitEnum;

/**
 * «Χαρακτηριστικά» — the variant axes (Χρώμα, Μέγεθος…) and their ordered values.
 * Variants are generated from these on a «με παραλλαγές» product
 * (docs/woocommerce-bridge-plan.md §0).
 */
class ProductAttributeResource extends Resource
{
    protected static ?string $model = ProductAttribute::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedSwatch;

    protected static string|UnitEnum|null $navigationGroup = 'Είδη & Προμήθειες';

    protected static ?int $navigationSort = 11;

    protected static ?string $modelLabel = 'Χαρακτηριστικό';

    protected static ?string $pluralModelLabel = 'Χαρακτηριστικά';

    protected static ?string $navigationLabel = 'Χαρακτηριστικά';

    protected static ?string $recordTitleAttribute = 'name';

    /**
     * Variants using this attribute block its deletion (the pivot FK is
     * restrictOnDelete — this turns the raw DB error into a readable message).
     *
     * @return array<string, int>
     */
    public static function dependents(Model $record): array
    {
        return [
            'παραλλαγές' => DB::table('product_variant_values')
                ->where('product_attribute_id', $record->getKey())
                ->count(),
        ];
    }

    public static function form(Schema $schema): Schema
    {
        return ProductAttributeForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return ProductAttributesTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListProductAttributes::route('/'),
            'create' => CreateProductAttribute::route('/create'),
            'edit' => EditProductAttribute::route('/{record}/edit'),
        ];
    }
}
