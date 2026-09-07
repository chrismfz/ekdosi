<?php

namespace App\Filament\Clusters;

use App\Models\Company;
use BackedEnum;
use Filament\Clusters\Cluster;
use Filament\Facades\Filament;
use UnitEnum;

/**
 * «Domains» — the Domains pillar (Πυλώνας A) as its own gated cluster: ONE nav
 * entry that (when enabled) opens the domain-management area. Same discipline
 * as SupportCluster per `docs/menu-ia.md` («each new pillar = one top entry,
 * hidden unless enabled») — future screens (domains list, TLDs, pricing) join it.
 *
 * Hidden entirely unless the tenant has the pillar switched on
 * (`companies.enable_domain_management`, default off — only MyIP). Design:
 * docs/domains/README.md §8.1.
 */
class DomainsCluster extends Cluster
{
    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-globe-alt';

    protected static string|UnitEnum|null $navigationGroup = 'Καθημερινά';

    protected static ?int $navigationSort = 101;

    protected static ?string $slug = 'domains';

    protected static ?string $clusterBreadcrumb = 'Domains';

    public static function getNavigationLabel(): string
    {
        return 'Domains';
    }

    /**
     * Only when the tenant has the pillar enabled AND at least one member screen
     * is reachable (avoids an empty shell). The per-tenant gate is what keeps the
     * whole pillar invisible by default.
     */
    public static function canAccess(): bool
    {
        $tenant = Filament::getTenant();

        return $tenant instanceof Company
            && $tenant->hasDomainManagement()
            && static::canAccessClusteredComponents();
    }
}
