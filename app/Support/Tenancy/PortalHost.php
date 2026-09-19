<?php

namespace App\Support\Tenancy;

use App\Models\Company;

/**
 * The tenant resolved from the customer-portal request host (multi-domain #1c,
 * Option A "soft"). Set once per request by the `ResolvePortalHost` middleware
 * from `request()->getHost()` → the company whose `portal_host` matches, or null
 * on the shared default host / an unknown host.
 *
 * It is a language + branding HINT, not a security boundary: it decides the
 * GUEST pages' locale (via CustomerLanguage::forHost) and the login/layout
 * branding. Document access stays per-grant (cross-tenant), unchanged.
 *
 * Bound as a singleton (see AppServiceProvider); the middleware overwrites it on
 * every portal request, so there is no stale value to leak across requests.
 */
class PortalHost
{
    private ?Company $company = null;

    public function set(?Company $company): void
    {
        $this->company = $company;
    }

    public function company(): ?Company
    {
        return $this->company;
    }

    public function has(): bool
    {
        return $this->company !== null;
    }
}
