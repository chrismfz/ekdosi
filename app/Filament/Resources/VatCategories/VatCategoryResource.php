<?php

namespace App\Filament\Resources\VatCategories;

use App\Filament\Clusters\SettingsCluster;
use App\Filament\Resources\VatCategories\Pages\CreateVatCategory;
use App\Filament\Resources\VatCategories\Pages\EditVatCategory;
use App\Filament\Resources\VatCategories\Pages\ListVatCategories;
use App\Filament\Resources\VatCategories\Schemas\VatCategoryForm;
use App\Filament\Resources\VatCategories\Tables\VatCategoriesTable;
use App\Filament\Support\GuardedDeleteAction;
use App\Models\Product;
use App\Models\VatCategory;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletingScope;

class VatCategoryResource extends Resource
{
    protected static ?string $model = VatCategory::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedReceiptPercent;

    protected static ?string $cluster = SettingsCluster::class;

    protected static ?int $navigationSort = 60;

    protected static ?string $modelLabel = 'Κατηγορία ΦΠΑ';

    protected static ?string $pluralModelLabel = 'Κατηγορίες ΦΠΑ';

    protected static ?string $recordTitleAttribute = 'description';

    /**
     * SET-2: dependent-record counts that block deletion (single + bulk + force).
     * ONE source of truth — the Edit page's guard and the table's bulk/force
     * guards all read this map.
     *
     * @return array<string, int> label => count
     */
    public static function dependents(Model $record): array
    {
        return [
            'προϊόντα' => GuardedDeleteAction::count(Product::class, 'vat_category_id', $record->id),
        ];
    }

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
