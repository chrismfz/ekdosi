<?php

namespace App\Http\Middleware;

use App\Support\Install\InstallState;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Global gate that routes a PRISTINE host into the web installer and, once the
 * app is configured, makes the installer disappear.
 *
 * Registered as the FIRST global middleware (prepended) so it runs BEFORE the
 * session / cookie-encryption stack — critical, because on a fresh host there
 * is no APP_KEY, and letting the panel's `web` middleware run would 500 while
 * decrypting cookies / opening a DB session. Here we short-circuit to a plain
 * redirect (no session, no DB, no APP_KEY) instead.
 *
 * Two directions:
 *  - NOT installed → every non-installer request 302s to /install.
 *  - installed     → the installer routes 302 back to /admin (permanently inert).
 *
 * The `installed?` check is filesystem-only and marker-first (one stat() on a
 * live system), so the per-request cost is negligible.
 */
class EnsureInstalled
{
    public function __construct(private readonly InstallState $state) {}

    public function handle(Request $request, Closure $next): Response
    {
        $onInstaller = $request->is('install', 'install/*');

        if ($this->state->canInstall()) {
            // Pristine host: let the installer (and the health check) through,
            // send everything else to the wizard.
            if ($onInstaller || $request->is('up')) {
                return $next($request);
            }

            return redirect('/install');
        }

        // Configured host: the installer must never run again.
        if ($onInstaller) {
            return redirect('/admin');
        }

        return $next($request);
    }
}
