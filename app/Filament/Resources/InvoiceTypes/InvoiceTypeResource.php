<?php

namespace App\Filament\Resources\InvoiceTypes;

use App\Filament\Resources\InvoiceTypes\Pages\CreateInvoiceType;
use App\Filament\Resources\InvoiceTypes\Pages\EditInvoiceType;
use App\Filament\Resources\InvoiceTypes\Pages\ListInvoiceTypes;
use App\Filament\Resources\InvoiceTypes\Schemas\InvoiceTypeForm;
use App\Filament\Resources\InvoiceTypes\Tables\InvoiceTypesTable;
use App\Models\InvoiceType;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use UnitEnum;

class InvoiceTypeResource extends Resource
{
    protected static ?string $model = InvoiceType::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedDocumentText;

    protected static string|UnitEnum|null $navigationGroup = 'Setup';

    protected static ?int $navigationSort = 10;

    protected static ?string $recordTitleAttribute = 'name';

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->withoutGlobalScopes([SoftDeletingScope::class]);
    }

    public static function form(Schema $schema): Schema
    {
        return InvoiceTypeForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return InvoiceTypesTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListInvoiceTypes::route('/'),
            'create' => CreateInvoiceType::route('/create'),
            'edit' => EditInvoiceType::route('/{record}/edit'),
        ];
    }
}
