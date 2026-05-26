<?php

namespace App\Providers;

use BezhanSalleh\FilamentShield\Support\Utils as ShieldUtils;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use Spatie\Permission\PermissionRegistrar;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
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
            },
        );
    }
}
