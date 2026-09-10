<?php

namespace App\Filament\Widgets\Concerns;

use App\Models\Company;
use App\Models\MyDataMark;
use Filament\Widgets\StatsOverviewWidget\Stat;

/**
 * PROV-009: the «Υπόλοιπο εκδόσεων» card of a gr-provider tenant, built in ONE
 * place because it renders in two spots depending on the tenant:
 *
 *  - inline as the 4th card of the myDATA ΦΠΑ row (MyDataPictureStats) — high on
 *    the dashboard, where the operator actually looks;
 *  - on its own «Πάροχος ΥΠΑΗΕΣ» widget (ProviderQuotaStats) when that row is
 *    hidden, i.e. a provider tenant with no myDATA READ credentials.
 *
 * Exactly one of the two shows it (ProviderQuotaStats::canView() stands down when
 * the ΦΠΑ row is up), so the card is never duplicated and never lost.
 *
 * The reading is this tenant's own latest filing — correct for the normal case of
 * one InvoSign contract per tenant. If a single provider account ever backed several
 * ekdosi tenants, each would see its own last reading (a per-tenant partial view of
 * the shared quota) — documented in docs/BACKLOG.md, not our setup today.
 */
trait BuildsProviderQuotaStat
{
    /**
     * THE predicate for «does this tenant get a quota card at all» — static so the
     * fallback widget's static canView() and the ΦΠΑ row's instance code consult the
     * SAME rule. Keeping it in one place is the point: two independent copies would
     * let a future edit to one make the card vanish from both surfaces at once (the
     * row skips it, the widget has already stood down).
     */
    public static function providerQuotaCardApplies(?Company $tenant): bool
    {
        return $tenant?->einvoice_provider === 'gr-provider';
    }

    protected function providerQuotaStat(Company $tenant, string $label = 'Υπόλοιπο εκδόσεων'): Stat
    {
        // Scope to the tenant's ACTIVE provider (einvoice_provider_key === the
        // transport key that stamps provider_key on its PROVIDER_INSERT marks), so a
        // tenant migrated between providers reads the current account's quota, not a
        // stale reading left by the old one.
        // NOTE: reads the raw column, exactly as this query did before it moved here. A
        // key carrying stray whitespace would miss every mark — but that is a
        // pre-existing, repo-wide asymmetry (the registry trims before the transport
        // stamps the mark) whose root fix is normalising on WRITE, and whose worst site
        // silently wipes the provider API token. Filed in docs/BACKLOG.md; deliberately
        // NOT half-fixed here.
        $latest = MyDataMark::query()
            ->where('company_id', $tenant->id)
            ->where('provider_key', (string) $tenant->einvoice_provider_key)
            ->whereNotNull('remaining_invoices')
            ->latest('id')
            ->first();

        if ($latest === null) {
            return Stat::make($label, '—')
                ->description('Καμία υποβολή μέσω παρόχου ακόμη')
                ->descriptionIcon('heroicon-m-paper-airplane')
                ->color('gray');
        }

        $remaining = (int) $latest->remaining_invoices;
        $threshold = (int) config('ekdosi.einvoice.provider_low_quota_threshold', 50);

        [$color, $desc] = match (true) {
            $remaining <= 0 => ['danger', 'Εξαντλήθηκε — απαιτείται ανανέωση'],
            $remaining <= $threshold => ['warning', "Χαμηλό (≤ {$threshold}) — προγραμμάτισε ανανέωση"],
            default => ['success', 'Επαρκές υπόλοιπο'],
        };

        // The provider reports the quota ONLY on an issue response — there is no poll
        // (PROV-005: InvoSign documents no free non-issuing status endpoint). So the
        // timestamp is «when we last filed», not «when we last checked»; wording it
        // that way stops a quiet week reading as a broken refresh.
        $asOf = $latest->created_at?->diffForHumans();

        return Stat::make($label, number_format($remaining, 0, ',', '.'))
            ->description(trim($desc.($asOf !== null ? " · τελευταία υποβολή {$asOf}" : '')))
            ->descriptionIcon('heroicon-m-paper-airplane')
            ->color($color);
    }
}
