<?php

namespace App\Filament\Resources\DomainRegistrarConnections;

use App\Filament\Clusters\DomainsCluster;
use App\Filament\Resources\DomainRegistrarConnections\Pages\CreateDomainRegistrarConnection;
use App\Filament\Resources\DomainRegistrarConnections\Pages\EditDomainRegistrarConnection;
use App\Filament\Resources\DomainRegistrarConnections\Pages\ListDomainRegistrarConnections;
use App\Filament\Resources\DomainRegistrarConnections\Schemas\DomainRegistrarConnectionForm;
use App\Filament\Resources\DomainRegistrarConnections\Tables\DomainRegistrarConnectionsTable;
use App\Models\Company;
use App\Models\DomainRegistrarConnection;
use BackedEnum;
use Filament\Facades\Filament;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

/**
 * «Συνδέσεις registrar» — per-tenant registrar accounts (Πυλώνας A / A0). The
 * first member of the Domains cluster: add an account, name it, pick its mode.
 * Runtime behaviour is resolved by the `registrar` key through
 * DomainRegistrarRegistry; adding a registrar is a config line + a class, not a
 * resource edit. Super-admin only — these rows carry credentials (the
 * PaymentGatewayConnectionResource discipline), so company_admin/operator never
 * see them; their Domains screens arrive with A1.
 */
class DomainRegistrarConnectionResource extends Resource
{
    protected static ?string $model = DomainRegistrarConnection::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedServerStack;

    protected static ?string $cluster = DomainsCluster::class;

    protected static ?int $navigationSort = 90;

    protected static ?string $modelLabel = 'Σύνδεση registrar';

    protected static ?string $pluralModelLabel = 'Συνδέσεις registrar';

    protected static ?string $navigationLabel = 'Συνδέσεις registrar';

    protected static ?string $recordTitleAttribute = 'label';

    /** Pillar flag AND system super-admin — credentials never reach tenant admins. */
    public static function canAccess(): bool
    {
        return Filament::getTenant() instanceof Company
            && Filament::getTenant()->hasDomainManagement()
            && (bool) auth()->user()?->isSystemSuperAdmin();
    }

    public static function form(Schema $schema): Schema
    {
        return DomainRegistrarConnectionForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return DomainRegistrarConnectionsTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListDomainRegistrarConnections::route('/'),
            'create' => CreateDomainRegistrarConnection::route('/create'),
            'edit' => EditDomainRegistrarConnection::route('/{record}/edit'),
        ];
    }
}
