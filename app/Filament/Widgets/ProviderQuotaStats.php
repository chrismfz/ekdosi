<?php

namespace App\Filament\Widgets;

use App\Filament\Widgets\Concerns\BuildsProviderQuotaStat;
use Filament\Facades\Filament;
use Filament\Widgets\StatsOverviewWidget;

/**
 * «Πάροχος ΥΠΑΗΕΣ» — the running quota of the tenant's provider account (PROV-009).
 *
 * The provider (InvoSign) returns `remaining_invoices` on EVERY issue response, so
 * the latest provider mark holds the freshest reading with zero polling. The card
 * turns warning/danger as the account approaches the low-quota threshold
 * (config('ekdosi.einvoice.provider_low_quota_threshold')) — nudging the operator to
 * top up before it runs dry mid-day. Provider tenants only; «—» until the first
 * filing reports a quota.
 *
 * FALLBACK ONLY. The card normally rides along as the 4th stat of the myDATA ΦΠΑ row
 * (MyDataPictureStats) so it sits high on the dashboard instead of near the bottom;
 * this standalone widget renders only for the tenant that has no such row — a
 * gr-provider tenant without myDATA READ credentials. The Stat itself is built by the
 * shared BuildsProviderQuotaStat trait, so both spots stay identical.
 */
class ProviderQuotaStats extends StatsOverviewWidget
{
    use BuildsProviderQuotaStat;

    protected static ?int $sort = 3;

    protected ?string $heading = 'Πάροχος ΥΠΑΗΕΣ';

    public static function canView(): bool
    {
        return Filament::getTenant()?->einvoice_provider === 'gr-provider'
            // Don't double-render: when the ΦΠΑ row is visible it carries the card.
            && ! MyDataPictureStats::canView();
    }

    protected function getStats(): array
    {
        $tenant = Filament::getTenant();
        if ($tenant === null) {
            return [];
        }

        return [$this->providerQuotaStat($tenant)];
    }
}
