<?php

namespace App\Support\EInvoice;

use RuntimeException;

/**
 * The ΥΠΑΗΕΣ-provider base URL is operator-supplied free text that the transport
 * POSTs the provider token + the complete invoice XML to. An `http://` typo, a
 * copied URL carrying userinfo/query, or a compromised/misconfigured value could
 * leak the token + legal payload, or target an internal service (SSRF). This guard
 * constrains it to a plain PUBLIC HTTPS endpoint and is enforced at the transport
 * choke-point (so CLI/API callers are covered), the provider preflight and the
 * form. PROV-017.
 *
 * Deliberately NOT covered here (deferred hardening — docs/BACKLOG.md): request-time
 * DNS-rebinding (TOCTOU) pinning and a provider-managed endpoint-profile registry.
 */
final class ProviderEndpointGuard
{
    public static function assertSafeBaseUrl(string $url, string $label = 'παρόχου'): void
    {
        $parts = parse_url($url);

        if ($parts === false || empty($parts['scheme']) || empty($parts['host'])) {
            self::reject($label, 'μη έγκυρη μορφή URL');
        }

        if (strtolower($parts['scheme']) !== 'https') {
            self::reject($label, 'απαιτείται https (όχι http ή άλλο σχήμα)');
        }

        if (isset($parts['user']) || isset($parts['pass'])) {
            self::reject($label, 'δεν επιτρέπονται στοιχεία σύνδεσης (user:pass@) στο URL');
        }

        if (isset($parts['query']) || isset($parts['fragment'])) {
            self::reject($label, 'δεν επιτρέπονται παράμετροι query/fragment στο URL');
        }

        if (isset($parts['port']) && (int) $parts['port'] !== 443) {
            self::reject($label, 'επιτρέπεται μόνο η θύρα 443');
        }

        self::assertPublicHost((string) $parts['host'], $label);
    }

    /**
     * Reject a host that resolves to a private/reserved/loopback/link-local
     * address (SSRF). A literal IP is checked directly; a hostname is resolved
     * to BOTH its A and AAAA records (an IPv4-only lookup would let an AAAA-only
     * internal host through) — best-effort: a DNS failure is a connectivity issue
     * (the request would fail on its own), not a policy violation, so it does not
     * block.
     */
    private static function assertPublicHost(string $host, string $label): void
    {
        $host = trim($host, '[]'); // IPv6 literal brackets

        if (filter_var($host, FILTER_VALIDATE_IP)) {
            $ips = [$host];
        } else {
            $ips = [];
            foreach (@dns_get_record($host, DNS_A | DNS_AAAA) ?: [] as $rec) {
                if (isset($rec['ip'])) {
                    $ips[] = $rec['ip'];
                }
                if (isset($rec['ipv6'])) {
                    $ips[] = $rec['ipv6'];
                }
            }
        }

        foreach ($ips as $ip) {
            if (! self::isPublicIp($ip)) {
                self::reject($label, 'εσωτερική/μη δημόσια διεύθυνση δεν επιτρέπεται');
            }
        }
    }

    private static function isPublicIp(string $ip): bool
    {
        // PHP's private+reserved flags cover RFC1918, loopback, link-local
        // (incl. 169.254.169.254 metadata) and their IPv6 equivalents.
        if (! filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
            return false;
        }

        // Ranges those flags miss but that are still internal/non-routable-public.
        return ! self::ipv4InCidr($ip, '100.64.0.0', 10)    // CGNAT (RFC 6598)
            && ! self::ipv4InCidr($ip, '198.18.0.0', 15);   // benchmarking (RFC 2544)
    }

    private static function ipv4InCidr(string $ip, string $subnet, int $bits): bool
    {
        if (! filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            return false;
        }

        $ipL = ip2long($ip) & 0xFFFFFFFF;
        $subL = ip2long($subnet) & 0xFFFFFFFF;
        $mask = (0xFFFFFFFF << (32 - $bits)) & 0xFFFFFFFF;

        return ($ipL & $mask) === ($subL & $mask);
    }

    private static function reject(string $label, string $why): never
    {
        throw new RuntimeException("Μη έγκυρο/μη ασφαλές URL {$label}: {$why}.");
    }
}
