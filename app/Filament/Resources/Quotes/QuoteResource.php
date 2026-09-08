<?php

namespace App\Filament\Resources\Quotes;

use App\Filament\Resources\Quotes\Pages\CreateQuote;
use App\Filament\Resources\Quotes\Pages\EditQuote;
use App\Filament\Resources\Quotes\Pages\ListQuotes;
use App\Filament\Resources\Quotes\Pages\ViewQuote;
use App\Filament\Resources\Quotes\RelationManagers\MailLogRelationManager;
use App\Filament\Resources\Quotes\Schemas\QuoteForm;
use App\Filament\Resources\Quotes\Tables\QuotesTable;
use App\Models\Quote;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use UnitEnum;

/**
 * Προσφορές (Quotes). A non-legal sales offer — see App\Models\Quote.
 *
 * Access is governed by QuotePolicy (ViewAny:Quote …) — no canAccess override.
 * `Quote` is in the operator role's curated permission set, so operators +
 * company_admins + super_admins see it; missing-permission users fall through
 * to a clean deny (Gate::can returns false, never the PermissionDoesNotExist
 * throw), so there's no 404 storm before shield:generate has run.
 */
class QuoteResource extends Resource
{
    protected static ?string $model = Quote::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedDocumentDuplicate;

    protected static string|UnitEnum|null $navigationGroup = 'Καθημερινά';

    protected static ?int $navigationSort = 30;

    protected static ?string $recordTitleAttribute = 'code';

    public static function getNavigationLabel(): string
    {
        return 'Προσφορές';
    }

    public static function getModelLabel(): string
    {
        return 'Προσφορά';
    }

    public static function getPluralModelLabel(): string
    {
        return 'Προσφορές';
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->withoutGlobalScopes([SoftDeletingScope::class])
            ->with(['customer' => fn ($q) => $q->withTrashed()]);
    }

    public static function getGloballySearchableAttributes(): array
    {
        return ['code', 'subject', 'vat_no', 'company_name'];
    }

    public static function getGlobalSearchResultDetails(Model $record): array
    {
        return array_filter([
            'Πελάτης' => $record->company_name,
            'ΑΦΜ' => $record->vat_no,
        ]);
    }

    public static function form(Schema $schema): Schema
    {
        return QuoteForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return QuotesTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [
            MailLogRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListQuotes::route('/'),
            'create' => CreateQuote::route('/create'),
            'view' => ViewQuote::route('/{record}'),
            'edit' => EditQuote::route('/{record}/edit'),
        ];
    }
}
