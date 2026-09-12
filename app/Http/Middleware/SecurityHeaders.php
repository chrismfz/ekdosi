<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Adds a small set of always-safe security response headers to every response.
 *
 * Deliberately conservative — NO Content-Security-Policy and NO HSTS here:
 *  - CSP is risky with Filament/Livewire/Alpine (inline scripts + the 2FA QR
 *    `data:` image) and needs report-only tuning first; kept out on purpose.
 *  - HSTS is sticky (browsers cache it) and would need a per-deployment decision
 *    about includeSubDomains across *.myip.gr; left to the edge/ops config.
 *
 * What ships:
 *  - X-Content-Type-Options: nosniff  — no MIME sniffing.
 *  - Referrer-Policy: strict-origin-when-cross-origin — don't leak full URLs.
 *  - X-Frame-Options: SAMEORIGIN — clickjacking guard on the panel + portal.
 *
 * `set()` (not add) so we never duplicate a header a downstream layer already
 * placed; existing values are overwritten with our safe baseline.
 */
class SecurityHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('Referrer-Policy', 'strict-origin-when-cross-origin');
        $response->headers->set('X-Frame-Options', 'SAMEORIGIN');

        return $response;
    }
}
