<?php

namespace App\Filament\Resources\Domains;

use App\Filament\Clusters\DomainsCluster;
use App\Filament\RelationManagers\ActivityLogRelationManager;
use App\Filament\RelationManagers\AttachmentsRelationManager;
use App\Filament\RelationManagers\InternalNotesRelationManager;
use App\Filament\Resources\Domains\Pages\CreateDomain;
use App\Filament\Resources\Domains\Pages\EditDomain;
use App\Filament\Resources\Domains\Pages\ListDomains;
use App\Filament\Resources\Domains\Pages\ViewDomain;
use App\Filament\Resources\Domains\RelationManagers\ContactsRelationManager;
use App\Filament\Resources\Domains\RelationManagers\NameserversRelationManager;
use App\Filament\Resources\Domains\Schemas\DomainForm;
use App\Filament\Resources\Domains\Tables\DomainsTable;
use App\Models\Company;
use App\Models\Domain;
use BackedEnum;
use Filament\Facades\Filament;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;

/**
 * «Domains» — the tenant's domain portfolio (Πυλώνας A / A1b): list with work
 * tabs (incl. the «Χωρίς πελάτη» assign worklist), manual CRUD, the rich
 * per-domain view (contacts/NS parsed inline — the owner's core objection to
 * the WHMCS screen), «Ανάθεση σε πελάτη» + «Μεταφορά ιδιοκτησίας». Registrar
 * command actions arrive with A2/A3. docs/domains/README.md §8.2.
 */
class DomainResource extends Resource
{
    protected static ?string $model = Domain::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedGlobeAlt;

    protected static ?string $cluster = DomainsCluster::class;

    protected static ?int $navigationSort = 10;

    protected static ?string $modelLabel = 'Domain';

    protected static ?string $pluralModelLabel = 'Domains';

    protected static ?string $navigationLabel = 'Domains';

    protected static ?string $recordTitleAttribute = 'fqdn';

    /** Pillar flag AND Shield perms (operator has Create/Update via the map). */
    public static function canAccess(): bool
    {
        return Filament::getTenant() instanceof Company
            && Filament::getTenant()->hasDomainManagement()
            && parent::canAccess();
    }

    /** The assign worklist signal: ASSIGNABLE unassigned domains ring on the nav. */
    public static function getNavigationBadge(): ?string
    {
        $count = Domain::query()->assignable()->count();

        return $count > 0 ? (string) $count : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'warning';
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->withoutGlobalScopes([SoftDeletingScope::class]);
    }

    public static function form(Schema $schema): Schema
    {
        return DomainForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return DomainsTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [
            ContactsRelationManager::class,
            NameserversRelationManager::class,
            InternalNotesRelationManager::class,
            AttachmentsRelationManager::class,
            ActivityLogRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListDomains::route('/'),
            'create' => CreateDomain::route('/create'),
            'view' => ViewDomain::route('/{record}'),
            'edit' => EditDomain::route('/{record}/edit'),
        ];
    }
}
