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
 *
 * Also binds the session to the login's password HASH, so a password change
 * ANYWHERE (a reset from another device, a profile change, an operator reset)
 * logs out every OTHER session on its next request — the standard «changing your
 * password ends your other sessions» guarantee (Laravel's AuthenticateSession,
 * done explicitly for the custom `portal` guard).
 */
class EnsurePortalAuthenticated
{
    /** Session key holding the password hash this session was established with. */
    public const PW_HASH_KEY = 'portal_pw_hash';

    public function handle(Request $request, Closure $next): Response
    {
        $user = Auth::guard('portal')->user();

        if (! $user instanceof CustomerUser || ! $user->canLogin()) {
            Auth::guard('portal')->logout();

            // guest() stashes the intended URL (GET only), so after login the
            // customer lands back on the document/page they deep-linked to — the
            // new /user/document/{id}/pdf route makes this user-visible.
            return redirect()->guest(route('portal.login'));
        }

        // Password-change session invalidation. The hash lives server-side in the
        // session payload only (never sent to the client). Seed it on first
        // sighting — so a pre-existing session (or an actingAs() test) is not
        // force-logged-out — then a later hash mismatch means the password changed
        // elsewhere and THIS session is stale.
        $stored = $request->session()->get(self::PW_HASH_KEY);
        $current = (string) $user->getAuthPassword();

        if ($stored === null) {
            $request->session()->put(self::PW_HASH_KEY, $current);
        } elseif (! hash_equals($stored, $current)) {
            Auth::guard('portal')->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return redirect()->route('portal.login')
                ->with('status', 'Ο κωδικός του λογαριασμού άλλαξε. Συνδέσου ξανά.');
        }

        Auth::shouldUse('portal');

        return $next($request);
    }
}
