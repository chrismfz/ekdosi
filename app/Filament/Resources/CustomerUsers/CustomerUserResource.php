<?php

namespace App\Filament\Resources\CustomerUsers;

use App\Filament\Resources\CustomerUsers\Pages\CreateCustomerUser;
use App\Filament\Resources\CustomerUsers\Pages\EditCustomerUser;
use App\Filament\Resources\CustomerUsers\Pages\ListCustomerUsers;
use App\Filament\Resources\CustomerUsers\RelationManagers\AccessRelationManager;
use App\Filament\Resources\CustomerUsers\Schemas\CustomerUserForm;
use App\Filament\Resources\CustomerUsers\Tables\CustomerUsersTable;
use App\Models\CustomerUser;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use UnitEnum;

/**
 * Operator-side management of customer-portal logins («Χρήστες πύλης»). The
 * login identity is GLOBAL (unique email across all tenants), so the resource is
 * panel-level, NOT tenant-scoped — like UserResource.
 *
 * Super-admin only: who may sign into the customer portal is a system-level,
 * cross-tenant concern. A company_admin/operator never sees it. Per-company
 * grants (WHICH customer a login may view) are a separate, later slice with
 * their own finer-grained gate; this resource only manages the login itself.
 */
class CustomerUserResource extends Resource
{
    protected static ?string $model = CustomerUser::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedIdentification;

    protected static string|UnitEnum|null $navigationGroup = 'Πύλη πελατών';

    protected static ?int $navigationSort = 10;

    protected static ?string $modelLabel = 'Χρήστης πύλης';

    protected static ?string $pluralModelLabel = 'Χρήστες πύλης';

    // Global identity (customer_users has no company_id): manage at the panel
    // level, never filtered by the active tenant.
    protected static bool $isScopedToTenant = false;

    protected static ?string $recordTitleAttribute = 'email';

    public static function canAccess(): bool
    {
        return (bool) auth()->user()?->isSystemSuperAdmin();
    }

    /**
     * Drop the SoftDeletingScope so the TrashedFilter / RestoreAction can reach
     * soft-deleted logins (default listing still hides them until the operator
     * opts in via the filter). Without this a trashed row is invisible AND
     * unrestorable, yet still blocks its email — a dead end.
     */
    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->withoutGlobalScopes([SoftDeletingScope::class]);
    }

    public static function form(Schema $schema): Schema
    {
        return CustomerUserForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return CustomerUsersTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [
            AccessRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListCustomerUsers::route('/'),
            'create' => CreateCustomerUser::route('/create'),
            'edit' => EditCustomerUser::route('/{record}/edit'),
        ];
    }
}
