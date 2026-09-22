<?php

/*
|--------------------------------------------------------------------------
| Trusted proxies
|--------------------------------------------------------------------------
|
| Read by App\Http\Middleware\TrustProxies at REQUEST time. It
| lives in a config file (not an env() call in bootstrap/app.php) on purpose:
| bootstrap runs before .env is loaded, so a TRUSTED_PROXIES set only in .env
| used to be silently ignored.
|
*/

return [

    /*
     * Which proxies may tell us the client IP (X-Forwarded-For) and scheme
     * (X-Forwarded-Proto). Comma-separated IPs/CIDRs and/or the keywords:
     *
     *   local  loopback + every IP of THIS server (the CFM edge / a local nginx
     *          proxying from the same box). The default.
     *   none   trust nobody (a directly served host, or shared hosting where
     *          other tenants' processes also connect from this box).
     *   *      trust every hop — ONLY when the app is unreachable except through
     *          a trusted proxy, otherwise any client can forge its logged IP.
     *
     * X-Forwarded-Host / -Port / -Prefix are never trusted: the Host header the
     * edge passes through stays the host.
     */
    'proxies' => env('TRUSTED_PROXIES') ?: 'local',

];
