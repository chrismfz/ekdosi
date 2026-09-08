<?php

namespace App\Providers\Filament;

use App\Filament\Pages\Assistant;
use App\Filament\Pages\Dashboard;
use App\Models\Company;
use App\Support\BuildInfo;
use App\Support\Settings\SystemSettings;
use BezhanSalleh\FilamentShield\FilamentShieldPlugin;
use Filament\Auth\MultiFactor\App\AppAuthentication;
use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Support\Colors\Color;
use Filament\View\PanelsRenderHook;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\Facades\Blade;
use Illuminate\View\Middleware\ShareErrorsFromSession;

class AdminPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel
            ->default()
            ->id('admin')
            ->path('admin')
            ->login()
            ->profile()                       // adds "Edit profile" to the user menu (Filament built-in)
            // TOTP two-factor (authenticator app) + recovery codes. The setup,
            // QR enrollment, recovery-code generation and disable/regenerate
            // all live on the profile page automatically. Opt-in per user by
            // default; set EKDOSI_REQUIRE_2FA=true to force enrolment on next
            // login once the whole team is set up.
            ->multiFactorAuthentication(
                [AppAuthentication::make()->recoverable()],
                // DB override (set from «Ρυθμίσεις συστήματος») wins; env is the default.
                isRequired: app(SystemSettings::class)
                    ->bool('system.require_2fa', (bool) config('ekdosi.require_2fa', false)),
            )
            ->tenant(Company::class, slugAttribute: 'slug')
            // Bell + durable «άμεση τιμολόγηση» alerts (WhmcsInvoiceIngestor sends
            // a database notification when a paid immediate row is staged). Polls
            // so a new one surfaces within ~30s without a websocket server.
            ->databaseNotifications()
            // 60s, not 30s: this poll runs on EVERY open panel page (not just the
            // inbox, which has its own 30s table poll), so keep the global bell
            // poll lighter — a minute-latency alert is fine.
            ->databaseNotificationsPolling('60s')
            ->colors([
                'primary' => Color::Amber,
            ])
            // Explicit top-level navigation-group order (rest fall back to
            // discovery order). Keep these strings byte-identical to the
            // per-resource $navigationGroup values or a group silently splits.
            ->navigationGroups([
                'Καθημερινά',
                'Leads',
                'Είδη & Προμήθειες',
                'Διακίνηση',
                'Λογιστικά',
                'Διασυνδέσεις',
                'Πύλη πελατών',
                'Σύστημα',
                'Διαχείριση',
                // «Ρυθμίσεις» is no longer a flat group — it's the SettingsCluster
                // (in «Σύστημα», last). Config-ish system pages (GeneralSettings/
                // Scheduler/Preflight/Updates) live INSIDE it now; «Διαχείριση» =
                // super-admin Users/Companies/Roles. See docs/menu-ia.md Step 1.5.
            ])
            ->discoverResources(in: app_path('Filament/Resources'), for: 'App\Filament\Resources')
            ->discoverPages(in: app_path('Filament/Pages'), for: 'App\Filament\Pages')
            ->discoverClusters(in: app_path('Filament/Clusters'), for: 'App\Filament\Clusters')
            ->pages([
                Dashboard::class,
            ])
            // Operator dashboard widgets are auto-discovered from
            // app/Filament/Widgets and ordered by each widget's $sort.
            // The stock AccountWidget ("hello") + FilamentInfoWidget
            // (Filament version/links) are intentionally NOT registered.
            ->discoverWidgets(in: app_path('Filament/Widgets'), for: 'App\Filament\Widgets')
            ->middleware([
                EncryptCookies::class,
                AddQueuedCookiesToResponse::class,
                StartSession::class,
                AuthenticateSession::class,
                ShareErrorsFromSession::class,
                PreventRequestForgery::class,
                SubstituteBindings::class,
                DisableBladeIconComponents::class,
                DispatchServingFilamentEvent::class,
            ])
            // Floating AI «Βοηθός» on every page (chat while navigating). The
            // widget self-hides when the assistant isn't available for the tenant,
            // so injecting unconditionally is safe.
            ->renderHook(
                PanelsRenderHook::BODY_END,
                // Gate the injection itself so disabled tenants don't even mount
                // the widget (the component re-checks, but skip the mount cost).
                fn (): string => Assistant::assistantAvailable()
                    ? Blade::render('@livewire(\'assistant-widget\')')
                    : '',
            )
            // Build/version badge under the brand — the deployed identity
            // (v{SemVer} · {build stamp}) always in view for support/diagnostics.
            // Inline styles on purpose: the panel ships no Tailwind utility layer
            // (see CLAUDE.md «No-build CSS»), so a utility class would render bare.
            ->renderHook(
                PanelsRenderHook::SIDEBAR_NAV_START,
                fn (): string => '<div style="padding:0 .75rem .5rem;font-size:.7rem;line-height:1.2;opacity:.55;font-variant-numeric:tabular-nums;word-break:break-all" title="Έκδοση / build">'
                    .e(app(BuildInfo::class)->label()).'</div>',
            )
            ->plugins([
                // Shield's «Roles» resource → «Σύστημα» group as «Ρόλοι» (sort 30).
                // filament-shield 4.x reads the Role resource's nav group/sort/label
                // from the plugin instance (RoleResource::pluginOrParent), so these
                // fluent setters are the supported mechanism — no vendor config keys
                // exist for it. Empties the old 'Filament Shield' group so it vanishes.
                FilamentShieldPlugin::make()
                    ->navigationGroup('Διαχείριση')
                    ->navigationSort(30)
                    ->navigationLabel('Ρόλοι'),
            ])
            ->authMiddleware([
                Authenticate::class,
            ]);
    }
}
