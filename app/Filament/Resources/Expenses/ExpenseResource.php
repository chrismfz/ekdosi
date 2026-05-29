<?php

namespace App\Filament\Resources\Expenses;

use App\Filament\Resources\Expenses\Pages\ListExpenses;
use App\Filament\Resources\Expenses\Pages\ViewExpense;
use App\Filament\Resources\Expenses\RelationManagers\LinesRelationManager;
use App\Filament\Resources\Expenses\Schemas\ExpenseInfolist;
use App\Filament\Resources\Expenses\Tables\ExpensesTable;
use App\Models\Expense;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;

/**
 * Έξοδα — supplier documents (εισροές) recorded from myDATA. READ-ONLY at this
 * stage: records arrive via the myDATA expenses console import (E4); editing
 * an AADE-sourced doc isn't a thing yet (manual entry is a later phase). The
 * view page shows the header (infolist) + lines (relation manager).
 *
 * Tenant-scoped via the `company()` relation (Filament tenancy), like every
 * other resource.
 */
class ExpenseResource extends Resource
{
    protected static ?string $model = Expense::class;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-document-arrow-down';

    protected static ?string $navigationLabel = 'Έξοδα';

    protected static ?string $modelLabel = 'έξοδο';

    protected static ?string $pluralModelLabel = 'Έξοδα';

    protected static ?int $navigationSort = 16;

    public static function infolist(Schema $schema): Schema
    {
        return ExpenseInfolist::configure($schema);
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
            'view' => ViewExpense::route('/{record}'),
        ];
    }
}
