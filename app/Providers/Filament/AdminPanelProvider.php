<?php

namespace App\Providers\Filament;

use App\Filament\Pages\Dashboard;
use App\Models\Company;
use BezhanSalleh\FilamentShield\FilamentShieldPlugin;
use Filament\Auth\MultiFactor\App\AppAuthentication;
use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Support\Colors\Color;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
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
                isRequired: app(\App\Support\Settings\SystemSettings::class)
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
            ->plugins([
                FilamentShieldPlugin::make(),
            ])
            ->authMiddleware([
                Authenticate::class,
            ]);
    }
}
