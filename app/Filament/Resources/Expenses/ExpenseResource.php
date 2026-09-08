<?php

namespace App\Filament\Resources\Expenses;

use App\Enums\ExpenseSource;
use App\Filament\Resources\Expenses\Pages\CreateExpense;
use App\Filament\Resources\Expenses\Pages\EditExpense;
use App\Filament\Resources\Expenses\Pages\ListExpenses;
use App\Filament\Resources\Expenses\Pages\ViewExpense;
use App\Filament\Resources\Expenses\RelationManagers\LinesRelationManager;
use App\Filament\Resources\Expenses\Schemas\ExpenseForm;
use App\Filament\Resources\Expenses\Schemas\ExpenseInfolist;
use App\Filament\Resources\Expenses\Tables\ExpensesTable;
use App\Models\Expense;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use UnitEnum;

/**
 * Έξοδα — supplier documents (εισροές). myDATA-sourced records (sync /
 * self-declared) are READ-ONLY (they arrive via the myDATA expenses console);
 * MANUAL records (a supplier doc not in myDATA) can be created + edited here via
 * ExpenseForm. The view page shows the header (infolist) + lines (relation
 * manager); a private document can be attached + downloaded over a signed route.
 *
 * Tenant-scoped via the `company()` relation (Filament tenancy), like every
 * other resource.
 */
class ExpenseResource extends Resource
{
    protected static ?string $model = Expense::class;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-document-arrow-down';

    protected static string|UnitEnum|null $navigationGroup = 'Είδη & Προμήθειες';

    protected static ?string $navigationLabel = 'Έξοδα';

    protected static ?string $modelLabel = 'έξοδο';

    protected static ?string $pluralModelLabel = 'Έξοδα';

    protected static ?int $navigationSort = 40;

    public static function form(Schema $schema): Schema
    {
        return ExpenseForm::configure($schema);
    }

    public static function infolist(Schema $schema): Schema
    {
        return ExpenseInfolist::configure($schema);
    }

    /**
     * Only MANUAL expenses are hand-editable — a myDATA-sourced doc (sync /
     * self-declared) mirrors AADE and must not be edited locally.
     */
    public static function canEdit(Model $record): bool
    {
        return $record->source === ExpenseSource::Manual;
    }

    public static function table(Table $table): Table
    {
        return ExpensesTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [
            LinesRelationManager::class,
        ];
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->withoutGlobalScopes([SoftDeletingScope::class]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListExpenses::route('/'),
            'create' => CreateExpense::route('/create'),
            'view' => ViewExpense::route('/{record}'),
            'edit' => EditExpense::route('/{record}/edit'),
        ];
    }
}
