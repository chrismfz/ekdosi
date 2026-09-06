<?php

namespace App\Filament\Resources\UpdateRuns;

use App\Filament\Clusters\SettingsCluster;
use App\Filament\Resources\UpdateRuns\Pages\ListUpdateRuns;
use App\Filament\Resources\UpdateRuns\Pages\ViewUpdateRun;
use App\Filament\Resources\UpdateRuns\Schemas\UpdateRunInfolist;
use App\Filament\Resources\UpdateRuns\Tables\UpdateRunsTable;
use App\Models\UpdateRun;
use App\Services\TenantRoleProvisioner;
use BackedEnum;
use Filament\Facades\Filament;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

/**
 * In-app update (Phase 2) — history + live progress of application updates.
 *
 * GLOBAL, not tenant-scoped (`$isScopedToTenant = false`): an update is a
 * whole-app deploy, not company data. SUPER_ADMIN-ONLY and CROSS-TENANT, so the
 * panel is gated on `TenantRoleProvisioner::isSuperAdminAnywhere` (like the
 * SystemHealth page) rather than on a per-tenant Shield permission a
 * company_admin would hold.
 *
 * The model-level boundary is `App\Policies\UpdateRunPolicy` — hand-written, NOT
 * shield-generated (the stock template would approve any holder of
 * `*:UpdateRun`, which every company_admin used to get). `UpdateRun` is also in
 * `ADMIN_FORBIDDEN_RESOURCES`, so those permissions are no longer granted at
 * all. Committing the policy is ALSO what stops `shield:generate` from
 * re-creating it as an untracked file on every deploy/test run.
 *
 * Read-only: rows are created by the «Εγκατάσταση ενημέρωσης» action on the
 * SystemHealth page (never a Filament form) and mutated only by the
 * `ekdosi:self-update` worker. No create/edit/delete pages.
 */
class UpdateRunResource extends Resource
{
    protected static ?string $model = UpdateRun::class;

    protected static bool $isScopedToTenant = false;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-arrow-up-circle';

    protected static ?string $cluster = SettingsCluster::class;

    protected static string|\UnitEnum|null $navigationGroup = 'Λειτουργία';

    protected static ?string $navigationLabel = 'Ενημερώσεις';

    protected static ?string $modelLabel = 'ενημέρωση';

    protected static ?string $pluralModelLabel = 'Ενημερώσεις';

    protected static ?int $navigationSort = 98;

    protected static ?string $recordTitleAttribute = 'to_ref';

    public static function infolist(Schema $schema): Schema
    {
        return UpdateRunInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return UpdateRunsTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListUpdateRuns::route('/'),
            'view' => ViewUpdateRun::route('/{record}'),
        ];
    }

    // ── super_admin-only, cross-tenant (mirror SystemHealth::canAccess) ──────

    protected static function isSuperAdmin(): bool
    {
        $user = auth()->user();

        return Filament::getTenant() !== null
            && $user !== null
            && app(TenantRoleProvisioner::class)->isSuperAdminAnywhere($user);
    }

    public static function canViewAny(): bool
    {
        return static::isSuperAdmin();
    }

    public static function canView(Model $record): bool
    {
        return static::isSuperAdmin();
    }

    public static function canCreate(): bool
    {
        return false; // created only by the SystemHealth action
    }

    public static function canEdit(Model $record): bool
    {
        return false; // immutable audit trail
    }

    public static function canDelete(Model $record): bool
    {
        return false;
    }

    public static function shouldRegisterNavigation(): bool
    {
        return static::isSuperAdmin();
    }
}
