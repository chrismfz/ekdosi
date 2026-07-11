<?php

namespace App\Filament\Resources\BankAccounts;

use App\Filament\Resources\BankAccounts\Pages\CreateBankAccount;
use App\Filament\Resources\BankAccounts\Pages\EditBankAccount;
use App\Filament\Resources\BankAccounts\Pages\ListBankAccounts;
use App\Filament\Resources\BankAccounts\Schemas\BankAccountForm;
use App\Filament\Resources\BankAccounts\Tables\BankAccountsTable;
use App\Filament\Support\GuardedDeleteAction;
use App\Models\BankAccount;
use App\Models\Invoice;
use App\Models\Payment;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use UnitEnum;

class BankAccountResource extends Resource
{
    protected static ?string $model = BankAccount::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBuildingLibrary;

    protected static string|UnitEnum|null $navigationGroup = 'Setup';

    protected static ?int $navigationSort = 21;

    protected static ?string $recordTitleAttribute = 'bank_name';

    /**
     * SET-2: dependent counts blocking deletion (single + bulk + force). One source.
     *
     * @return array<string, int>
     */
    public static function dependents(Model $record): array
    {
        return [
            'τιμολόγια' => GuardedDeleteAction::count(Invoice::class, 'bank_account_id', $record->id),
            'πληρωμές' => GuardedDeleteAction::count(Payment::class, 'bank_account_id', $record->id),
        ];
    }

    public static function getModelLabel(): string
    {
        return 'Τραπεζικός λογαριασμός';
    }

    public static function getPluralModelLabel(): string
    {
        return 'Τραπεζικοί λογαριασμοί';
    }

    public static function getNavigationLabel(): string
    {
        return 'Τραπεζικοί λογαριασμοί';
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->withoutGlobalScopes([SoftDeletingScope::class]);
    }

    public static function form(Schema $schema): Schema
    {
        return BankAccountForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return BankAccountsTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListBankAccounts::route('/'),
            'create' => CreateBankAccount::route('/create'),
            'edit' => EditBankAccount::route('/{record}/edit'),
        ];
    }
}
