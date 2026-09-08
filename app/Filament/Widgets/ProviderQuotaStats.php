<?php

namespace App\Filament\Widgets;

use App\Models\MyDataMark;
use Filament\Facades\Filament;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

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
 * The reading is this tenant's own latest filing — correct for the normal case of
 * one InvoSign contract per tenant. If a single provider account ever backed several
 * ekdosi tenants, each would see its own last reading (a per-tenant partial view of
 * the shared quota) — documented in docs/BACKLOG.md, not our setup today.
 */
class ProviderQuotaStats extends StatsOverviewWidget
{
    protected static ?int $sort = 6;

    protected ?string $heading = 'Πάροχος ΥΠΑΗΕΣ';

    public static function canView(): bool
    {
        return Filament::getTenant()?->einvoice_provider === 'gr-provider';
    }

    protected function getStats(): array
    {
        $tenant = Filament::getTenant();
        if ($tenant === null) {
            return [];
        }

        // Scope to the tenant's ACTIVE provider (einvoice_provider_key === the
        // transport key that stamps provider_key on its PROVIDER_INSERT marks), so a
        // tenant migrated between providers reads the current account's quota, not a
        // stale reading left by the old one.
        $latest = MyDataMark::query()
            ->where('company_id', $tenant->id)
            ->where('provider_key', (string) $tenant->einvoice_provider_key)
            ->whereNotNull('remaining_invoices')
            ->latest('id')
            ->first();

        if ($latest === null) {
            return [
                Stat::make('Υπόλοιπο εκδόσεων', '—')
                    ->description('Καμία υποβολή μέσω παρόχου ακόμη')
                    ->descriptionIcon('heroicon-m-paper-airplane')
                    ->color('gray'),
            ];
        }

        $remaining = (int) $latest->remaining_invoices;
        $threshold = (int) config('ekdosi.einvoice.provider_low_quota_threshold', 50);

        [$color, $desc] = match (true) {
            $remaining <= 0 => ['danger', 'Εξαντλήθηκε — απαιτείται ανανέωση'],
            $remaining <= $threshold => ['warning', "Χαμηλό (≤ {$threshold}) — προγραμμάτισε ανανέωση"],
            default => ['success', 'Επαρκές υπόλοιπο'],
        };

        $asOf = $latest->created_at?->diffForHumans();

        return [
            Stat::make('Υπόλοιπο εκδόσεων', number_format($remaining, 0, ',', '.'))
                ->description(trim($desc.($asOf !== null ? " · ενημ. {$asOf}" : '')))
                ->descriptionIcon('heroicon-m-paper-airplane')
                ->color($color),
        ];
    }
}
