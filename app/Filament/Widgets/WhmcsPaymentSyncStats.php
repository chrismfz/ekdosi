<?php

namespace App\Filament\Widgets;

use App\Filament\Pages\WhmcsPaymentSync;
use App\Models\Company;
use App\Support\Whmcs\WhmcsPaymentSyncCache;
use Filament\Facades\Filament;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

/**
 * Dashboard tile for the WHMCS payment-sync worklist — the «εύκαιρη» summary
 * that jumps to the «Συγχρονισμός πληρωμών» page. Shows how many open
 * (επί πιστώσει) invoices WHMCS now reports Paid (a cheap cache read — no live
 * WHMCS call on dashboard load). Hidden entirely when there's nothing pending,
 * so it never adds noise to a quiet dashboard.
 */
class WhmcsPaymentSyncStats extends StatsOverviewWidget
{
    protected static ?int $sort = 6;

    public static function canView(): bool
    {
        $tenant = Filament::getTenant();

        return $tenant instanceof Company
            && ($tenant->hasWhmcsIntegration() || $tenant->whmcs_fetch_via_bridge)
            && (bool) auth()->user()?->can('ViewAny:PendingWhmcsInvoice')
            && count(WhmcsPaymentSyncCache::inboundIds($tenant)) > 0;
    }

    protected function getStats(): array
    {
        $tenant = Filament::getTenant();
        if (! $tenant instanceof Company) {
            return [];
        }

        $count = count(WhmcsPaymentSyncCache::inboundIds($tenant));

        return [
            Stat::make('Πληρωμές WHMCS προς καταγραφή', (string) $count)
                ->description('Ανοιχτά επί-πιστώσει που πληρώθηκαν στο WHMCS')
                ->descriptionIcon('heroicon-m-banknotes')
                ->color('success')
                ->url(WhmcsPaymentSync::getUrl(['tenant' => $tenant])),
        ];
    }
}
