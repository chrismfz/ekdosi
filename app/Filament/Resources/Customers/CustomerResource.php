<?php

namespace App\Filament\Resources\Customers;

use App\Filament\RelationManagers\ActivityLogRelationManager;
use App\Filament\RelationManagers\AttachmentsRelationManager;
use App\Filament\RelationManagers\InternalNotesRelationManager;
use App\Filament\Resources\Customers\Pages\CreateCustomer;
use App\Filament\Resources\Customers\RelationManagers\ContactsRelationManager;
use App\Filament\Resources\Customers\Pages\CustomerLedger;
use App\Filament\Resources\Customers\Pages\EditCustomer;
use App\Filament\Resources\Customers\Pages\ListCustomers;
use App\Filament\Resources\Customers\Schemas\CustomerForm;
use App\Filament\Resources\Customers\Tables\CustomersTable;
use App\Models\Customer;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletingScope;

class CustomerResource extends Resource
{
    protected static ?string $model = Customer::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedUserGroup;

    protected static ?string $recordTitleAttribute = 'name';

    /**
     * Top-bar global search across customer name + AFM. Tenant-scoped
     * automatically (the resource query already filters by the current
     * Company). Lets an operator jump to a customer by typing either
     * their name or their VAT number from anywhere in the panel.
     *
     * @return array<int, string>
     */
    public static function getGloballySearchableAttributes(): array
    {
        return ['name', 'afm'];
    }

    /**
     * @return array<string, string>
     */
    public static function getGlobalSearchResultDetails(Model $record): array
    {
        return array_filter([
            'ΑΦΜ' => $record->afm,
            'Πόλη' => $record->city,
        ]);
    }

    // Default is true — customers are per-tenant (the company_id FK does the
    // scoping). Filament's BelongsToTenant trait uses the `company()` relation
    // defined on the Customer model.

    /**
     * Lift the SoftDeletingScope at the resource-query level so the
     * TrashedFilter / Restore / ForceDelete bulk actions in the table
     * actually have soft-deleted rows to operate on. Without this,
     * those actions render as dead UI.
     */
    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->withoutGlobalScopes([SoftDeletingScope::class]);
    }

    public static function getGlobalSearchEloquentQuery(): Builder
    {
        // getEloquentQuery() lifts the SoftDeletingScope so the table's
        // TrashedFilter works — but global search shouldn't surface
        // trashed customers as live, badge-less hits. Re-exclude them.
        return parent::getGlobalSearchEloquentQuery()->whereNull('customers.deleted_at');
    }

    public static function form(Schema $schema): Schema
    {
        return CustomerForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return CustomersTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [
            ContactsRelationManager::class,
            InternalNotesRelationManager::class,
            AttachmentsRelationManager::class,
            ActivityLogRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListCustomers::route('/'),
            'create' => CreateCustomer::route('/create'),
            'edit' => EditCustomer::route('/{record}/edit'),
            'ledger' => CustomerLedger::route('/{record}/ledger'),
        ];
    }
}
