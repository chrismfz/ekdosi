<?php

namespace App\Http\Middleware;

use App\Models\CustomerUser;
use App\Support\CustomerLanguage;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Set the request locale for the customer portal from the logged-in customer
 * user's stored preference (`customer_users.locale`), via {@see CustomerLanguage}
 * (i18n Slice 0). A dedicated middleware kept SEPARATE from the auth guard so it
 * carries no security responsibility — it only picks a locale.
 *
 * Runs AFTER EnsurePortalAuthenticated in the portal group, so the `portal` guard
 * is already resolved. Until the portal blades are translated (a later slice)
 * this is inert plumbing — el stays el — but it establishes the single choke-point
 * every `__()` string will read once they exist.
 */
class SetPortalLocale
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = Auth::guard('portal')->user();

        app()->setLocale(CustomerLanguage::forUi($user instanceof CustomerUser ? $user : null));

        return $next($request);
    }
}
