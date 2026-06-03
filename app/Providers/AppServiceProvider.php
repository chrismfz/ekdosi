<?php

namespace App\Providers;

use App\Support\Tenancy\CompanyContext;
use BezhanSalleh\FilamentShield\Support\Utils as ShieldUtils;
use Illuminate\Support\Facades\DB;
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
         * super_admin role bypasses every policy. Combined with Spatie's
         * teams mode (team_foreign_key=company_id), this is a per-tenant
         * bypass — admin@ekdosi.local has super_admin in each of their
         * companies, so they see everything in each tenant context but
         * a hypothetical operator with super_admin only in tenant A
         * wouldn't bypass policies in tenant B.
         */
        Gate::before(function ($user, $ability) {
            return $user?->hasRole(ShieldUtils::getSuperAdminName()) ? true : null;
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
        \Illuminate\Support\Facades\Event::listen(
            \Filament\Events\TenantSet::class,
            function (\Filament\Events\TenantSet $event) {
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
