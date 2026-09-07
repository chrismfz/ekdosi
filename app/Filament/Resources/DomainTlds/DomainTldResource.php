<?php

namespace App\Filament\Resources\DomainTlds;

use App\Filament\Clusters\DomainsCluster;
use App\Filament\Resources\DomainTlds\Pages\CreateDomainTld;
use App\Filament\Resources\DomainTlds\Pages\EditDomainTld;
use App\Filament\Resources\DomainTlds\Pages\ListDomainTlds;
use App\Filament\Resources\DomainTlds\RelationManagers\PricesRelationManager;
use App\Filament\Resources\DomainTlds\Schemas\DomainTldForm;
use App\Filament\Resources\DomainTlds\Tables\DomainTldsTable;
use App\Filament\Support\GuardedDeleteAction;
use App\Models\Company;
use App\Models\Domain;
use App\Models\DomainTld;
use BackedEnum;
use Filament\Facades\Filament;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletingScope;

/**
 * «TLDs & τιμές» — the tenant's TLD catalogue (Πυλώνας A / A1): rules + routing
 * per TLD, with the explicit per-year prices as a relation. Operator-facing
 * (Create/Update per the owner decision — credentials live on the super_admin
 * connections resource, not here). docs/domains/README.md §3.2-3.3/§8.
 */
class DomainTldResource extends Resource
{
    protected static ?string $model = DomainTld::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedGlobeEuropeAfrica;

    protected static ?string $cluster = DomainsCluster::class;

    protected static ?int $navigationSort = 50;

    protected static ?string $modelLabel = 'TLD';

    protected static ?string $pluralModelLabel = 'TLDs & τιμές';

    protected static ?string $navigationLabel = 'TLDs & τιμές';

    protected static ?string $recordTitleAttribute = 'tld';

    /** Pillar flag AND Shield perms (operator has Create/Update via the map). */
    public static function canAccess(): bool
    {
        return Filament::getTenant() instanceof Company
            && Filament::getTenant()->hasDomainManagement()
            && parent::canAccess();
    }

    /**
     * Dependent counts blocking deletion (GuardedDeleteAction) — a TLD in use
     * by domains must not be deletable out from under them.
     *
     * @return array<string, int>
     */
    public static function dependents(Model $record): array
    {
        return [
            'domains' => GuardedDeleteAction::count(Domain::class, 'domain_tld_id', $record->id),
        ];
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->withoutGlobalScopes([SoftDeletingScope::class]);
    }

    public static function form(Schema $schema): Schema
    {
        return DomainTldForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return DomainTldsTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [
            PricesRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListDomainTlds::route('/'),
            'create' => CreateDomainTld::route('/create'),
            'edit' => EditDomainTld::route('/{record}/edit'),
        ];
    }
}
