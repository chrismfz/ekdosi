<?php

namespace App\Http\Middleware;

use App\Models\CustomerUser;
use App\Support\CustomerLanguage;
use App\Support\Tenancy\PortalHost;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Set the request locale for the customer portal, via {@see CustomerLanguage}
 * (i18n). A dedicated middleware kept SEPARATE from the auth guard so it carries
 * no security responsibility — it only picks a locale. Runs on ALL `/user` routes
 * (guest + auth), AFTER ResolvePortalHost.
 *
 *   - Authenticated: the logged-in customer user's stored preference
 *     (`customer_users.locale`) wins — per-person.
 *   - Guest (login/reset): fall back to the tenant resolved from the host (#1c),
 *     so e.g. the Estonian tenant's portal_host shows an English login page. No
 *     host / no tenant default → the app default.
 */
class SetPortalLocale
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = Auth::guard('portal')->user();

        app()->setLocale(CustomerLanguage::forPortal(
            $user instanceof CustomerUser ? $user : null,
            app(PortalHost::class)->company(),
        ));

        return $next($request);
    }
}
