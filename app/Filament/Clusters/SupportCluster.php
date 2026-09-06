<?php

namespace App\Filament\Clusters;

use App\Models\Company;
use BackedEnum;
use Filament\Clusters\Cluster;
use Filament\Facades\Filament;
use UnitEnum;

/**
 * «Υποστήριξη» — the Support/Ticket pillar (Πυλώνας E) as its own gated cluster:
 * ONE nav entry that (when enabled) opens the operator ticket area. Born as a
 * cluster per `docs/menu-ia.md` («each new pillar = one top entry, hidden unless
 * enabled»), so it never adds a flat pile — future Support screens (KB,
 * announcements) join it.
 *
 * Hidden entirely unless the tenant has the pillar switched on
 * (`companies.support_enabled`, default off). Sits with the daily drivers
 * («Καθημερινά», last) since for a tenant that DOES use it, support is
 * customer-facing daily work.
 */
class SupportCluster extends Cluster
{
    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-lifebuoy';

    protected static string|UnitEnum|null $navigationGroup = 'Καθημερινά';

    protected static ?int $navigationSort = 100;

    protected static ?string $slug = 'support';

    protected static ?string $clusterBreadcrumb = 'Υποστήριξη';

    public static function getNavigationLabel(): string
    {
        return 'Υποστήριξη';
    }

    /**
     * Only when the tenant has the pillar enabled AND at least one member screen is
     * reachable (avoids an empty shell). The per-tenant gate is what keeps the whole
     * pillar invisible by default.
     */
    public static function canAccess(): bool
    {
        $tenant = Filament::getTenant();

        return $tenant instanceof Company
            && $tenant->hasSupport()
            && static::canAccessClusteredComponents();
    }
}
