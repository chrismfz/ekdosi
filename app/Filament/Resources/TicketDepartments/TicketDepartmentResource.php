<?php

namespace App\Filament\Resources\TicketDepartments;

use App\Filament\Clusters\SettingsCluster;
use App\Filament\Resources\TicketDepartments\Pages\CreateTicketDepartment;
use App\Filament\Resources\TicketDepartments\Pages\EditTicketDepartment;
use App\Filament\Resources\TicketDepartments\Pages\ListTicketDepartments;
use App\Filament\Resources\TicketDepartments\Schemas\TicketDepartmentForm;
use App\Filament\Resources\TicketDepartments\Tables\TicketDepartmentsTable;
use App\Models\Company;
use App\Models\TicketDepartment;
use BackedEnum;
use Filament\Facades\Filament;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Table;

/**
 * Support departments config (Πυλώνας E) — lives in the Settings Cluster
 * «Υποστήριξη» sub-section, visible only for tenants with the pillar enabled.
 * Each department is a routing unit + (Phase 3) its own mailbox.
 */
class TicketDepartmentResource extends Resource
{
    protected static ?string $model = TicketDepartment::class;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-building-office-2';

    protected static ?string $cluster = SettingsCluster::class;

    protected static string|\UnitEnum|null $navigationGroup = 'Υποστήριξη';

    protected static ?int $navigationSort = 85;

    protected static ?string $modelLabel = 'Τμήμα υποστήριξης';

    protected static ?string $pluralModelLabel = 'Τμήματα υποστήριξης';

    protected static ?string $navigationLabel = 'Τμήματα υποστήριξης';

    protected static ?string $recordTitleAttribute = 'name';

    /** Only for tenants with the Support pillar enabled. */
    public static function canAccess(): bool
    {
        return Filament::getTenant() instanceof Company
            && Filament::getTenant()->hasSupport()
            && parent::canAccess();
    }

    public static function form(Schema $schema): Schema
    {
        return TicketDepartmentForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return TicketDepartmentsTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListTicketDepartments::route('/'),
            'create' => CreateTicketDepartment::route('/create'),
            'edit' => EditTicketDepartment::route('/{record}/edit'),
        ];
    }
}
