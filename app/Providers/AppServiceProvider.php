<?php

namespace App\Providers;

use App\Models\User;
use App\Support\Settings\SystemSettings;
use App\Support\Tenancy\CompanyContext;
use Filament\Events\TenantSet;
use Filament\Support\Assets\Css;
use Filament\Support\Facades\FilamentAsset;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use Spatie\Permission\PermissionRegistrar;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Ambient tenant for the CompanyScope global scope. Singleton so the
        // current company id lives for the whole request / command.
        $this->app->singleton(CompanyContext::class);

        // Deploy-wide settings store — singleton so the loaded map is shared
        // (one DB/cache read per process; the scheduler reads it on every tick).
        $this->app->singleton(SystemSettings::class);
    }

    public function boot(): void
    {
        /*
         * Hard block on destructive DB commands (db:wipe, migrate:fresh,
         * migrate:refresh) anywhere EXCEPT the automated test suite.
         *
         * Gated on the testing env — deliberately NOT app()->isProduction() —
         * because the prod box was mislabeled APP_ENV=local, which would have
         * left this guard (and Laravel's own prompts) OFF exactly when it was
         * needed: a stray `php artisan test` with cached prod config once ran
         * RefreshDatabase against the live MariaDB and wiped it. Tying the
         * guard to "not testing" makes it fire regardless of how APP_ENV is
         * set, while RefreshDatabase (APP_ENV=testing) still works in CI.
         * Plain `migrate` is unaffected — deploys keep working.
         */
        DB::prohibitDestructiveCommands(! $this->app->environment('testing'));

        /*
         * No-build panel utility CSS. The admin panel ships only Filament's
         * component CSS and registers no custom Tailwind theme, so utility classes
         * in our custom blade pages went unstyled. This hand-written supplement
         * (resources/css/panel.css) is copied into public + injected into the
         * panel <head> by `filament:assets` (which runs on every composer install
         * via filament:upgrade) — no npm / Vite build. See the file header.
         */
        FilamentAsset::register([
            Css::make('ekdosi-panel', resource_path('css/panel.css')),
        ]);

        /*
         * @gup('Κείμενο') — Greek ALL-CAPS without τόνος, for PDF/print labels.
         * CSS text-transform:uppercase keeps the accent (wrong in Greek + ugly in
         * DomPDF); this echoes App\Support\GreekText::upper() instead.
         */
        Blade::directive('gup', fn (string $expr) => "<?php echo e(\App\Support\GreekText::upper($expr)); ?>");

        /*
         * super_admin is GLOBAL (the operator), not a per-tenant role: a user who
         * holds super_admin in ANY tenant bypasses every policy in EVERY tenant.
         * So the owner sees everything in a freshly created/restored company the
         * moment they're attached — no per-company super_admin assignment needed
         * (which also kills the role-picker chicken-and-egg). Data isolation is
         * unaffected: CompanyScope still filters tenant-owned queries to the
         * current Filament tenant — only the PERMISSION bypass is global. Per-
         * tenant company_admin/operator roles are unchanged (they scope non-super
         * users). isSystemSuperAdmin() is a single memoised query, cheap on this
         * hot path.
         */
        Gate::before(function ($user) {
            return ($user instanceof User && $user->isSystemSuperAdmin()) ? true : null;
        });

        /*
         * Sync Filament's current tenant into Spatie's PermissionRegistrar
         * so `hasRole()` / `hasPermissionTo()` scope queries to the right
         * team. Without this, a user's role in tenant A would also satisfy
         * checks in tenant B, defeating the whole point of teams mode.
         *
         * IMPORTANT: hook on the TenantSet event, NOT Filament::serving().
         * serving() fires before the tenant middleware resolves the
         * current tenant, so Filament::getTenant() returns null there
         * and the team id never gets set. TenantSet fires AFTER the
         * tenant is identified, which is exactly when the gate bypass
         * for super_admin needs the right team scope.
         */
        Event::listen(
            TenantSet::class,
            function (TenantSet $event) {
                app(PermissionRegistrar::class)
                    ->setPermissionsTeamId($event->getTenant()->getKey());

                // Pin the ambient company so the CompanyScope global scope
                // auto-filters raw tenant-owned model queries made anywhere
                // inside the panel request (actions, pages, widgets, jobs
                // dispatched synchronously) — not just Filament's resource
                // queries.
                app(CompanyContext::class)->set($event->getTenant());
            },
        );
    }
}
