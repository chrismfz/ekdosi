<?php

namespace App\Filament\Clusters;

use BackedEnum;
use Filament\Clusters\Cluster;
use UnitEnum;

/**
 * «Κονσόλα myDATA» — one nav item that groups the three live-AADE consoles as
 * sub-navigation tabs: Πωλήσεις (sales), Έξοδα (expenses), Επισκόπηση Ε3.
 *
 * Each member page stays its OWN Livewire component — its own fetch actions,
 * `RemembersLastFetch` cache key (so each tab keeps its own «τελευταία ενημέρωση»),
 * per-page `View:*` permission, and lazy fetch (switching tabs is plain navigation,
 * it does NOT re-hit AADE). The cluster only consolidates the menu.
 *
 * The Phase-1 local «Συμφωνία myDATA» (`MyDataReconciliation`) deliberately stays
 * OUTSIDE the cluster: it's operator-accessible and makes NO AADE call, a different
 * purpose + gating than these three admin consoles.
 *
 * The nav item shows only when at least one member is accessible
 * (`canAccessClusteredComponents()`), which already encodes the tenant's
 * `canReadMyData()` + per-page permission — so non-gr-mydata tenants and operators
 * never see it.
 */
class MyDataCluster extends Cluster
{
    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-cloud';

    protected static string|UnitEnum|null $navigationGroup = 'Διασυνδέσεις';

    protected static ?int $navigationSort = 20;

    protected static ?string $slug = 'mydata';

    protected static ?string $clusterBreadcrumb = 'Κονσόλα myDATA';

    public static function getNavigationLabel(): string
    {
        return 'Κονσόλα myDATA';
    }

    /**
     * Route-level guard for the cluster shell itself: reachable only if at least
     * one member console is (so a hand-typed URL can't land on an empty shell).
     * The shell's mount() then redirects to the first accessible tab.
     */
    public static function canAccess(): bool
    {
        return static::canAccessClusteredComponents();
    }
}
