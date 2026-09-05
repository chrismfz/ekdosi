<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Gate portal routes on the `portal` guard — a dedicated middleware rather than
 * the generic `auth:portal`, so an unauthenticated customer is redirected to the
 * PORTAL login (never the operator/Filament login) and the operator web-guard
 * flows are left completely untouched. Also pins the request's default guard to
 * `portal` so `auth()->user()` / `@auth` resolve to the CustomerUser in views.
 */
class EnsurePortalAuthenticated
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! Auth::guard('portal')->check()) {
            return redirect()->route('portal.login');
        }

        Auth::shouldUse('portal');

        return $next($request);
    }
}
