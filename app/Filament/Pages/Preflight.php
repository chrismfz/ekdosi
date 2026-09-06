<?php

namespace App\Filament\Pages;

use App\Filament\Clusters\SettingsCluster;
use App\Services\TenantRoleProvisioner;
use App\Support\Preflight\ReadinessReport;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Pages\Page;
use Illuminate\Support\Facades\Cache;

/**
 * «Έλεγχος ετοιμότητας» — the config-readiness twin of «Υγεία συστήματος»
 * (liveness). A cross-tenant «είμαι νόμιμος;» checklist over {@see ReadinessReport}:
 * myDATA config (reusing the SAME MyDataConfigAudit as the «Έλεγχος ρυθμίσεων» tab
 * and `mydata:preflight`), the seeded lookup tables, product VAT coverage and the
 * optional WHMCS mappings. Read-only; links each dimension to where it's fixed and
 * to the «Οδηγός κωδικών myDATA» for the §8.3 / §8.12 reference tables.
 *
 * SUPER_ADMIN-ONLY (cross-tenant, like «Υγεία συστήματος»), «Σύστημα» area.
 */
class Preflight extends Page
{
    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-clipboard-document-check';

    protected static ?string $cluster = SettingsCluster::class;

    protected static ?int $navigationSort = 98;

    protected string $view = 'filament.pages.preflight';

    /** @var list<array<string, mixed>> per-company readiness (ReadinessReport::build()). */
    public array $report = [];

    private const CACHE_KEY = 'preflight.report';

    private const CACHE_TTL = 30; // seconds — cheap re-mounts reuse; «Ανανέωση» busts it.

    public function mount(): void
    {
        $this->report = Cache::remember(
            self::CACHE_KEY,
            self::CACHE_TTL,
            fn () => app(ReadinessReport::class)->build(),
        );
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('refresh')
                ->label('Ανανέωση')
                ->icon('heroicon-o-arrow-path')
                ->action(function (): void {
                    // Rebuild AND re-store, so a concurrent viewer within the TTL reuses
                    // this fresh walk instead of triggering another full cross-tenant rebuild.
                    $this->report = app(ReadinessReport::class)->build();
                    Cache::put(self::CACHE_KEY, $this->report, self::CACHE_TTL);
                }),
            Action::make('guide')
                ->label('Οδηγός κωδικών (§8.3 / §8.12)')
                ->icon('heroicon-o-book-open')
                ->color('gray')
                ->url(fn () => MyDataCodeGuide::getUrl(panel: Filament::getCurrentPanel()?->getId(), tenant: Filament::getTenant()))
                ->visible(fn () => MyDataCodeGuide::canAccess()),
        ];
    }

    public static function getNavigationLabel(): string
    {
        return 'Έλεγχος ετοιμότητας';
    }

    public function getTitle(): string
    {
        return 'Έλεγχος ετοιμότητας';
    }

    public static function getNavigationGroup(): ?string
    {
        return null; // lives in SettingsCluster now
    }

    public static function shouldRegisterNavigation(): bool
    {
        return static::canAccess();
    }

    public static function canAccess(): bool
    {
        $user = auth()->user();

        return Filament::getTenant() !== null
            && $user !== null
            && app(TenantRoleProvisioner::class)->isSuperAdminAnywhere($user);
    }
}
