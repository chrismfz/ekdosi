<?php

namespace App\Filament\Resources\DomainRegistrarConnections;

use App\Filament\Clusters\DomainsCluster;
use App\Filament\Resources\DomainRegistrarConnections\Pages\CreateDomainRegistrarConnection;
use App\Filament\Resources\DomainRegistrarConnections\Pages\EditDomainRegistrarConnection;
use App\Filament\Resources\DomainRegistrarConnections\Pages\ListDomainRegistrarConnections;
use App\Filament\Resources\DomainRegistrarConnections\Schemas\DomainRegistrarConnectionForm;
use App\Filament\Resources\DomainRegistrarConnections\Tables\DomainRegistrarConnectionsTable;
use App\Filament\Support\GuardedDeleteAction;
use App\Models\Company;
use App\Models\Domain;
use App\Models\DomainRegistrarConnection;
use App\Models\DomainTld;
use App\Services\Domains\DomainRegistrarRegistry;
use BackedEnum;
use Filament\Facades\Filament;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Database\Eloquent\Model;

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

    /** «Κενό όνομα → όνομα registrar» — also for the edit-page heading/breadcrumb. */
    public static function getRecordTitle(?Model $record): string|Htmlable|null
    {
        if ($record instanceof DomainRegistrarConnection && ($record->label === null || $record->label === '')) {
            return app(DomainRegistrarRegistry::class)->label((string) $record->registrar);
        }

        return parent::getRecordTitle($record);
    }

    /** Pillar flag AND system super-admin — credentials never reach tenant admins. */
    public static function canAccess(): bool
    {
        return Filament::getTenant() instanceof Company
            && Filament::getTenant()->hasDomainManagement()
            && (bool) auth()->user()?->isSystemSuperAdmin();
    }

    /**
     * In-use guard (GuardedDeleteAction): TLD routing and domains still point
     * through this connection — deleting it silently degrades them to manual.
     *
     * @return array<string, int>
     */
    public static function dependents(Model $record): array
    {
        return [
            'TLDs (δρομολόγηση)' => GuardedDeleteAction::count(DomainTld::class, 'registrar_connection_id', $record->id),
            'domains' => GuardedDeleteAction::count(Domain::class, 'registrar_connection_id', $record->id),
        ];
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
