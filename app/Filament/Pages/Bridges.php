<?php

namespace App\Filament\Pages;

use App\Filament\Resources\Companies\CompanyResource;
use App\Models\Company;
use App\Services\Billing\BillingSourceRegistry;
use BackedEnum;
use Filament\Facades\Filament;
use Filament\Pages\Page;

/**
 * «Γέφυρες» — the per-tenant list of billing bridges (WHMCS, and future
 * WooCommerce/Blesta/…), each with its capabilities + a truthful «is it
 * configured?» status + a link to where it's set up.
 *
 * Honest by design (Bridges/Connectors Phase 0.5): this is a READ-MOSTLY
 * surface. It does NOT carry an enable/disable toggle, because the live WHMCS
 * pipeline keys off the tenant's `whmcs_*` credentials, NOT a
 * `billing_connections.is_active` flag — a toggle here would be cosmetic and
 * lie. So «status» is DERIVED from real config (WHMCS «ρυθμισμένο» =
 * credentials present), and «Ρυθμίσεις» links to where you actually configure
 * it. The genuine on/off + per-source credentials (moving `companies.whmcs_*`
 * into `billing_connections.config`) land in Phase 1 — WHEN a real 2nd bridge
 * exists to design the contract against. The list grows automatically as
 * sources are registered in `config/ekdosi.php → billing.sources`.
 *
 * Gated on `View:Bridges` (company_admin sees the list + status; configuring
 * WHMCS still needs `update` on the Company, i.e. super_admin — so the
 * «Ρυθμίσεις» button only renders for those who can act on it).
 */
class Bridges extends Page
{
    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-puzzle-piece';

    protected static ?int $navigationSort = 10;

    protected string $view = 'filament.pages.bridges';

    /**
     * @var list<array{key:string,label:string,doc_noun:string,external_id_label:string,
     *     write_back:bool,third_party:bool,status:string,status_label:string,
     *     status_color:string,settings_url:?string}>
     */
    public array $bridges = [];

    public function mount(): void
    {
        $tenant = $this->tenant();
        $registry = app(BillingSourceRegistry::class);
        $canConfigure = (bool) auth()->user()?->can('update', $tenant);

        $this->bridges = [];
        foreach ($registry->all() as $key => $source) {
            $caps = $source->capabilities();
            [$status, $statusLabel, $statusColor, $settingsUrl] = $this->statusFor($key, $tenant, $canConfigure);

            $this->bridges[] = [
                'key' => $key,
                'label' => $source->label(),
                'doc_noun' => $caps->docNoun,
                'external_id_label' => $caps->externalIdLabel,
                'write_back' => $caps->supportsWriteBack,
                'third_party' => $caps->supportsThirdParty,
                'status' => $status,
                'status_label' => $statusLabel,
                'status_color' => $statusColor,
                'settings_url' => $settingsUrl,
            ];
        }
    }

    /**
     * Truthful per-source status + where to configure it. Only WHMCS has a real
     * config check today; any future source with no wiring yet shows as
     * «planned» (visible, not yet operable) rather than a fake «active».
     *
     * @return array{0:string,1:string,2:string,3:?string}
     */
    private function statusFor(string $key, Company $tenant, bool $canConfigure): array
    {
        if ($key === 'whmcs') {
            $configured = $tenant->hasWhmcsIntegration();

            return [
                $configured ? 'configured' : 'unconfigured',
                $configured ? 'Ρυθμισμένο' : 'Μη ρυθμισμένο',
                $configured ? 'success' : 'gray',
                $canConfigure ? CompanyResource::getUrl('edit', ['record' => $tenant]) : null,
            ];
        }

        // A registered-but-unwired future source: show it, don't pretend it works.
        return ['planned', 'Διαθέσιμο (Phase 1)', 'warning', null];
    }

    public static function getNavigationLabel(): string
    {
        return 'Γέφυρες';
    }

    public function getTitle(): string
    {
        return 'Γέφυρες — πηγές τιμολόγησης';
    }

    public static function getNavigationGroup(): ?string
    {
        return 'Διασυνδέσεις';
    }

    public static function shouldRegisterNavigation(): bool
    {
        return static::canAccess();
    }

    public static function canAccess(): bool
    {
        return Filament::getTenant() instanceof Company
            && (bool) auth()->user()?->can('View:Bridges');
    }

    /** The current tenant (canAccess guarantees a Company before we get here). */
    private function tenant(): Company
    {
        $tenant = Filament::getTenant();
        if (! $tenant instanceof Company) {
            abort(403);
        }

        return $tenant;
    }
}
