<?php

namespace App\Http\Middleware;

use App\Models\Company;
use App\Support\Tenancy\PortalHost;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\View;
use Symfony\Component\HttpFoundation\Response;

/**
 * Resolve the tenant that owns the current portal host (multi-domain #1c,
 * Option A "soft") and stash it in {@see PortalHost}. Runs on ALL `/user` routes
 * (guest + auth), BEFORE SetPortalLocale, so the guest login/reset pages can pick
 * the tenant's language + branding.
 *
 * On the shared default host (no `companies.portal_host` match) the resolved
 * company is null → app-default language + generic branding (today's behaviour).
 * This is a language/branding hint only; it does NOT scope document access
 * (that stays per-grant, cross-tenant).
 */
class ResolvePortalHost
{
    public function handle(Request $request, Closure $next): Response
    {
        $company = Company::resolveByPortalHost($request->getHost());

        // Single source for the locale middleware + programmatic reads…
        app(PortalHost::class)->set($company);
        // …and a convenient handle for the layout / login blades (branding).
        View::share('portalCompany', $company);

        return $next($request);
    }
}
