<?php

namespace App\Http\Middleware;

use App\Models\CustomerUser;
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
 *
 * Re-checks the login's STATUS on every request (the session guard reloads the
 * user from the DB), so an account suspended/soft-deleted AFTER login is logged
 * out on its next request instead of keeping access until the session expires —
 * the boundary the documents view relies on (a live login only).
 */
class EnsurePortalAuthenticated
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = Auth::guard('portal')->user();

        if (! $user instanceof CustomerUser || ! $user->canLogin()) {
            Auth::guard('portal')->logout();

            return redirect()->route('portal.login');
        }

        Auth::shouldUse('portal');

        return $next($request);
    }
}
