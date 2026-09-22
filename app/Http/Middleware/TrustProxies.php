<?php

namespace App\Http\Middleware;

use Illuminate\Http\Middleware\TrustProxies as Middleware;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\IpUtils;

/**
 * Trust the reverse proxy / edge (CFM, nginx, a CDN) so request()->ip() — and
 * with it the auth/security log, «Τελ. σύνδεση» IP and every per-IP throttle —
 * sees the REAL client from X-Forwarded-For, and isSecure() sees the client's
 * scheme from X-Forwarded-Proto.
 *
 * Configured by `trustedproxy.proxies` (TRUSTED_PROXIES), read per request. The
 * default `local` trusts only this box itself: loopback plus the server's own
 * addresses, which is exactly where a same-host edge (CFM with DNAT) connects
 * from. A client can't spoof those source addresses over TCP, so a forged
 * X-Forwarded-For from outside is ignored.
 */
class TrustProxies extends Middleware
{
    /**
     * FOR (client IP) + PROTO (https behind a TLS-terminating edge) only. Not
     * HOST/PREFIX (the edge passes the real Host through; trusting a forwarded
     * one would let a proxy hop rewrite our URLs) and not PORT (an edge that
     * forwards its backend port would leak `:8443` into every generated URL —
     * the port follows from Host + scheme instead).
     *
     * @var int
     */
    protected $headers = Request::HEADER_X_FORWARDED_FOR | Request::HEADER_X_FORWARDED_PROTO;

    /** Loopback in IPv4, IPv6 and IPv4-mapped IPv6 form. */
    private const LOOPBACK = ['127.0.0.0/8', '::1', '::ffff:127.0.0.0/104'];

    /** @var list<string>|null this box's interface addresses, memoised for the process */
    private static ?array $interfaceAddresses = null;

    /**
     * Keywords `local` / `none` / `*` and IPs/CIDRs from `trustedproxy.proxies`.
     * (Laravel's `REMOTE_ADDR` keyword and `TrustProxies::at()` are not used
     * here — this config value is the single knob.)
     */
    protected function setTrustedProxyIpAddresses(Request $request)
    {
        // Nothing forwarded → nothing to trust; skip resolving (interface lookup)
        // for the bulk of requests. handle() already reset the list.
        if (! $request->headers->has('X-Forwarded-For') && ! $request->headers->has('X-Forwarded-Proto')) {
            return;
        }

        $proxies = self::resolve((string) config('trustedproxy.proxies'), $request);

        if ($proxies === []) {
            return;   // handle() already reset the list: nobody is trusted
        }

        $request->setTrustedProxies($proxies, $this->getTrustedHeaderNames());
    }

    /**
     * Expand a TRUSTED_PROXIES value into the concrete list to trust.
     *
     * @return list<string>
     */
    public static function resolve(string $value, Request $request): array
    {
        $proxies = [];

        foreach (array_filter(array_map('trim', explode(',', $value)), 'strlen') as $entry) {
            $proxies = match (strtolower($entry)) {
                'none' => $proxies,
                'local' => [...$proxies, ...self::localAddresses($request)],
                '*', '**' => [...$proxies, '0.0.0.0/0', '::/0'],
                default => [...$proxies, $entry],
            };
        }

        return array_values(array_unique($proxies));
    }

    /** @return list<string> loopback + the address this request came in on (+ every interface address if needed) */
    private static function localAddresses(Request $request): array
    {
        $addresses = self::LOOPBACK;

        // The address the web server accepted this connection on — a same-host
        // proxy dialling the public IP arrives FROM that same address.
        $serverAddr = (string) $request->server->get('SERVER_ADDR', '');
        if (filter_var($serverAddr, FILTER_VALIDATE_IP) !== false) {
            array_push($addresses, ...self::withMappedForm($serverAddr));
        }

        // The usual edge hop matches already — skip enumerating interfaces
        // (a syscall per request under FPM, where statics don't survive).
        $peer = (string) $request->server->get('REMOTE_ADDR', '');
        if ($peer !== '' && IpUtils::checkIp($peer, $addresses)) {
            return $addresses;
        }

        foreach (self::interfaceAddresses() as $ip) {
            array_push($addresses, ...self::withMappedForm($ip));
        }

        return $addresses;
    }

    /**
     * An IPv4 address plus its IPv4-mapped IPv6 twin (and back): a dual-stack
     * listener reports an IPv4 peer — or its own address — as ::ffff:a.b.c.d.
     *
     * @return list<string>
     */
    private static function withMappedForm(string $ip): array
    {
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false) {
            return [$ip, '::ffff:'.$ip];
        }

        $v4 = str_starts_with(strtolower($ip), '::ffff:') ? substr($ip, 7) : '';

        return filter_var($v4, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false ? [$ip, $v4] : [$ip];
    }

    /** @return list<string> */
    private static function interfaceAddresses(): array
    {
        if (self::$interfaceAddresses !== null) {
            return self::$interfaceAddresses;
        }

        $found = [];

        // Absent or blocked by disable_functions on some hosts → SERVER_ADDR + loopback still apply.
        $interfaces = function_exists('net_get_interfaces') ? @net_get_interfaces() : false;

        foreach (is_array($interfaces) ? $interfaces : [] as $interface) {
            foreach ($interface['unicast'] ?? [] as $unicast) {
                $ip = (string) ($unicast['address'] ?? '');
                // Link-local (169.254/16, fe80::/10) is unique only per link: another
                // host on a different segment can hold the same address. Skip it.
                if (filter_var($ip, FILTER_VALIDATE_IP) !== false
                    && ! IpUtils::checkIp($ip, ['169.254.0.0/16', 'fe80::/10'])) {
                    $found[] = $ip;
                }
            }
        }

        return self::$interfaceAddresses = array_values(array_unique($found));
    }

    public static function flushState()
    {
        parent::flushState();
        self::$interfaceAddresses = null;
    }
}
