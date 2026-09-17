<?php

namespace App\Filament\Resources\ServiceContracts;

use App\Enums\ServiceContractStatus;
use App\Filament\RelationManagers\ActivityLogRelationManager;
use App\Filament\Resources\ServiceContracts\Pages\CreateServiceContract;
use App\Filament\Resources\ServiceContracts\Pages\EditServiceContract;
use App\Filament\Resources\ServiceContracts\Pages\ListServiceContracts;
use App\Filament\Resources\ServiceContracts\Pages\ViewServiceContract;
use App\Filament\Resources\ServiceContracts\RelationManagers\RenewalsRelationManager;
use App\Filament\Resources\ServiceContracts\Schemas\ServiceContractForm;
use App\Filament\Resources\ServiceContracts\Tables\ServiceContractsTable;
use App\Models\ServiceContract;
use BackedEnum;
use Filament\Facades\Filament;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use UnitEnum;

/**
 * Recurring service contracts (Υπηρεσίες) — the per-customer subscription
 * instances (WHMCS «Services»). NOT money: each contract STAGES a draft
 * renewal invoice when its next_due_date arrives (operator-gated, never
 * auto-AADE); the invoice carries the money via the normal lifecycle.
 *
 * Top-level nav with a warning badge counting Active contracts due within
 * the next few days, mirroring the WhmcsInbox pending-count badge.
 */
class ServiceContractResource extends Resource
{
    protected static ?string $model = ServiceContract::class;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-arrow-path-rounded-square';

    protected static string|UnitEnum|null $navigationGroup = 'Είδη & Προμήθειες';

    protected static ?string $navigationLabel = 'Υπηρεσίες';

    protected static ?string $modelLabel = 'υπηρεσία';

    protected static ?string $pluralModelLabel = 'Υπηρεσίες';

    protected static ?int $navigationSort = 20;

    protected static ?string $recordTitleAttribute = 'description';

    /** Days ahead a contract counts as "soon due" for the nav badge. */
    private const BADGE_LEAD_DAYS = 7;

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->withoutGlobalScopes([SoftDeletingScope::class]);
    }

    /**
     * Nav badge: Active contracts whose next_due_date is on/before today +
     * lead days — "X subscriptions about to renew". Mirrors the WhmcsInbox
     * pending-count badge.
     */
    public static function getNavigationBadge(): ?string
    {
        $tenant = Filament::getTenant();
        if (! $tenant) {
            return null;
        }
        $count = ServiceContract::query()
            ->where('company_id', $tenant->getKey())
            ->where('status', ServiceContractStatus::Active->value)
            ->whereNotNull('next_due_date')
            ->whereDate('next_due_date', '<=', today()->addDays(self::BADGE_LEAD_DAYS))
            ->count();

        return $count > 0 ? (string) $count : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'warning';
    }

    public static function form(Schema $schema): Schema
    {
        return ServiceContractForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return ServiceContractsTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [
            // «Ανανεώσεις» — the invoices billed from this contract (staged
            // renewals + retro-linked); read-only, the tangible billing history.
            RenewalsRelationManager::class,
            // «Ιστορικό» — the contract's audit trail (manual + automated dunning
            // suspends/terminates), via the shared read-only relation manager.
            ActivityLogRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListServiceContracts::route('/'),
            'create' => CreateServiceContract::route('/create'),
            'view' => ViewServiceContract::route('/{record}'),
            'edit' => EditServiceContract::route('/{record}/edit'),
        ];
    }
}
