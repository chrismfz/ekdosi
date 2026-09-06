<?php

namespace App\Filament\Clusters;

use BackedEnum;
use Filament\Clusters\Cluster;
use UnitEnum;

/**
 * «Ρυθμίσεις» — one nav entry that consolidates the tenant's configuration/lookup
 * screens (invoice types, payment methods, VAT categories, gateways, units, tags,
 * company settings, …) that used to sprawl as a 13-item «Ρυθμίσεις» sidebar group.
 *
 * This is Step 1 of the Menu/IA plan (`docs/menu-ia.md`): the config bulk moves OUT
 * of the daily nav into its own cluster (WHMCS «Configuration» / Blesta «Settings»
 * pattern), so the everyday sidebar stays short. Each member keeps its own
 * Resource/Page, permission and URL (now under `/settings/…`); the cluster only
 * groups the menu + gives shared sub-navigation.
 *
 * Sits at the bottom of the nav (ungrouped, high sort) — «tucked away», reached when
 * you need to configure something, not while doing daily work. Visible only when at
 * least one member is accessible.
 */
class SettingsCluster extends Cluster
{
    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-cog-6-tooth';

    // Filament renders ungrouped items at the TOP; to keep config «tucked away» we
    // place the cluster in the bottom «Σύστημα» admin zone (sort 1 = its first item).
    // A later Menu/IA step folds «Σύστημα»'s own config into this same cluster.
    protected static string|UnitEnum|null $navigationGroup = 'Σύστημα';

    protected static ?int $navigationSort = 1;

    protected static ?string $slug = 'settings';

    protected static ?string $clusterBreadcrumb = 'Ρυθμίσεις';

    public static function getNavigationLabel(): string
    {
        return 'Ρυθμίσεις';
    }

    /** Reachable only if at least one member screen is (avoids an empty shell). */
    public static function canAccess(): bool
    {
        return static::canAccessClusteredComponents();
    }
}
