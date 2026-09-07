<?php

namespace App\Services\Domains\Registrars;

use App\Contracts\DomainRegistrar;
use App\Enums\DomainStatus;
use App\Models\Domain;
use App\Services\Domains\DomainRegistrarNotConfigured;
use App\Support\Domains\AvailabilityResult;
use App\Support\Domains\DomainRegistrarCapabilities;
use App\Support\Domains\DomainRegistrarCredentials;
use App\Support\Domains\DomainSyncResult;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Openprovider REST adapter (Πυλώνας A / A2) — docs/domains/README.md §4.3.
 *
 * DELIBERATELY READ-ONLY: this class contains NO mutating endpoint (no
 * register/renew/transfer/update/delete) until the A3 write slice — so a
 * connection configured with PRODUCTION credentials cannot cause any charge
 * or state change at the registrar, by construction (owner runs real creds
 * from day one). The write surface arrives with A3's idempotency/reconciler.
 *
 * Auth: POST /v1beta/auth/login (plaintext password over HTTPS — the MD5/token
 * variant is legacy XML-RPC) → bearer, ~24h TTL. We cache it for 6h keyed by
 * (endpoint × username) and re-login ONCE on a 401 mid-flight.
 */
class OpenproviderRegistrar implements DomainRegistrar
{
    private const PRODUCTION_URL = 'https://api.openprovider.eu';

    // HTTP on :8480 and a .nl host — the registrar's documented sandbox; the
    // old *.cte.openprovider.eu was retired (docs/domains/README.md §4.3).
    private const SANDBOX_URL = 'http://api.sandbox.openprovider.nl:8480';

    private const TOKEN_TTL_SECONDS = 6 * 3600;

    public function key(): string
    {
        return 'openprovider';
    }

    public function capabilities(): DomainRegistrarCapabilities
    {
        return new DomainRegistrarCapabilities(
            supportsPricingSync: true,
            supportsPrivacy: true,
            supportsDnssec: true,
            supportsTransferLock: true,
            supportsTransfer: true,
            supportsGlueHosts: true,
        );
    }

    /** Contract: never throws — false on missing creds / bad auth / transport failure. */
    public function ping(DomainRegistrarCredentials $credentials): bool
    {
        try {
            return $this->freshToken($credentials) !== null;
        } catch (\Throwable) {
            return false;
        }
    }

    public function checkAvailability(string $fqdn, DomainRegistrarCredentials $credentials): AvailabilityResult
    {
        [$name, $extension] = $this->splitFqdn($fqdn);
        $fqdn = $name.'.'.$extension; // normalized (lowercased/trimmed)

        $response = $this->request($credentials, 'POST', '/v1beta/domains/check', [
            'domains' => [['name' => $name, 'extension' => $extension]],
        ]);

        $result = $response->json('data.results.0');
        if (! is_array($result)) {
            throw new RuntimeException('Το Openprovider δεν επέστρεψε αποτέλεσμα διαθεσιμότητας για το '.$fqdn.'.');
        }

        $status = (string) ($result['status'] ?? '');
        // The API may return a structured (array) reason for premium/claims
        // names — only a scalar is presentable; fall back to the raw status.
        $reason = is_scalar($result['reason'] ?? null) ? (string) $result['reason'] : null;

        return new AvailabilityResult(
            fqdn: $fqdn,
            available: $status === 'free',
            reason: $status === 'free' ? null : ($reason ?? ($status !== '' ? $status : null)),
        );
    }

    /**
     * Registrar truth for one domain. Uses the stored numeric OP id when we
     * have it; otherwise resolves it via ?full_name= (and the result carries it
     * so the caller can persist it — docs/domains/README.md §4.3 gap note).
     */
    public function syncDomain(Domain $domain, DomainRegistrarCredentials $credentials): DomainSyncResult
    {
        $data = $this->fetchDomainData($domain, $credentials);

        $rawStatus = isset($data['status']) ? (string) $data['status'] : null;
        $expiresAt = null;
        if (is_string($data['expiration_date'] ?? null) && $data['expiration_date'] !== '') {
            // OP returns "YYYY-MM-DD HH:MM:SS" — the date part is our clock.
            $expiresAt = substr($data['expiration_date'], 0, 10);
        }

        $nameservers = [];
        foreach ((array) ($data['name_servers'] ?? []) as $ns) {
            $host = is_array($ns) ? ($ns['name'] ?? null) : (is_string($ns) ? $ns : null);
            if (is_string($host) && $host !== '') {
                $nameservers[] = mb_strtolower($host);
            }
        }

        return new DomainSyncResult(
            expiresAt: $expiresAt,
            nameservers: $nameservers,
            registrarDomainId: isset($data['id']) ? (string) $data['id'] : null,
            status: $this->mapStatus($rawStatus),
            rawStatus: $rawStatus,
        );
    }

    /** @return array<string, mixed> */
    private function fetchDomainData(Domain $domain, DomainRegistrarCredentials $credentials): array
    {
        // Authenticate ONCE up front: a login rejection must throw here (bad
        // creds = one failed POST per run, not one per fallback branch — OP
        // rate-limits repeated auth failures) and never be eaten by the
        // stale-id catch below, which is for domain-fetch errors only.
        $this->token($credentials);

        if ($domain->registrar_domain_id !== null && $domain->registrar_domain_id !== '') {
            try {
                $data = $this->request($credentials, 'GET', '/v1beta/domains/'.rawurlencode($domain->registrar_domain_id))->json('data');
                if (is_array($data)) {
                    return $data;
                }
            } catch (RuntimeException) {
                // Stale/wrong stored id (object re-created under a new id, or a
                // typo) — fall through to the by-name resolve instead of failing
                // the domain's sync forever. The by-name result carries the new
                // id and DomainSyncService adopts it (no repeat next run).
            }
        }

        [$name, $extension] = $this->splitFqdn($domain->fqdn);
        $results = $this->request($credentials, 'GET', '/v1beta/domains?full_name='.rawurlencode($name.'.'.$extension))
            ->json('data.results');
        $first = is_array($results) ? ($results[0] ?? null) : null;
        if (! is_array($first)) {
            throw new RuntimeException('Το '.$domain->fqdn.' δεν βρέθηκε στον λογαριασμό Openprovider.');
        }

        return $first;
    }

    /**
     * Openprovider status → our lifecycle, CONFIDENT mappings only — anything
     * else returns null (keep the local status, surface the raw value).
     */
    private function mapStatus(?string $raw): ?DomainStatus
    {
        return match ($raw) {
            'ACT' => DomainStatus::Active,
            'DEL' => DomainStatus::Deleted,
            'PEN', 'REQ' => DomainStatus::PendingRegister,
            default => null,
        };
    }

    // ── plumbing ───────────────────────────────────────────────────────────

    /**
     * Authenticated request with ONE re-login retry on 401 (the cached bearer
     * expired server-side). Non-2xx → RuntimeException carrying Openprovider's
     * own error description (their envelope: code/desc).
     */
    private function request(DomainRegistrarCredentials $credentials, string $method, string $path, array $payload = []): Response
    {
        $response = $this->send($credentials, $this->token($credentials), $method, $path, $payload);

        if ($response->status() === 401) {
            Cache::forget($this->tokenCacheKey($credentials));
            $response = $this->send($credentials, $this->token($credentials), $method, $path, $payload);
        }

        if ($response->failed()) {
            $desc = $response->json('desc');

            throw new RuntimeException(
                'Openprovider error '.$response->status().': '.(is_scalar($desc) && $desc !== '' ? (string) $desc : $response->body())
            );
        }

        return $response;
    }

    private function send(DomainRegistrarCredentials $credentials, string $token, string $method, string $path, array $payload): Response
    {
        return Http::withToken($token)
            ->acceptJson()
            ->timeout(30)
            ->send($method, $this->baseUrl($credentials).$path, $payload === [] ? [] : ['json' => $payload]);
    }

    /**
     * Cached bearer (6h) — Openprovider tokens live ~24h; 401 mid-flight
     * re-logins. Cached ENCRYPTED: with the DB cache driver a plaintext entry
     * would put a live full-access bearer into the `cache` table (and every DB
     * dump), while the credentials themselves are encrypted at rest.
     */
    private function token(DomainRegistrarCredentials $credentials): string
    {
        $key = $this->tokenCacheKey($credentials);
        $cached = Cache::get($key);
        if (is_string($cached) && $cached !== '') {
            try {
                return Crypt::decryptString($cached);
            } catch (DecryptException) {
                Cache::forget($key); // stale/foreign ciphertext → re-login
            }
        }

        $token = $this->freshToken($credentials); // throws with OP's own message
        if ($token === null) {
            throw new RuntimeException('Αποτυχία σύνδεσης στο Openprovider (login δεν επέστρεψε token).');
        }
        Cache::put($key, Crypt::encryptString($token), self::TOKEN_TTL_SECONDS);

        return $token;
    }

    /**
     * Null only on a 2xx login without a token; a REJECTED login throws with
     * Openprovider's own description, so «λάθος credentials» is distinguishable
     * from a registrar outage in sync_error / the operator's notification.
     */
    private function freshToken(DomainRegistrarCredentials $credentials): ?string
    {
        if (! $credentials->has('username') || ! $credentials->has('password')) {
            throw new DomainRegistrarNotConfigured(
                'Η σύνδεση Openprovider δεν έχει username/password — συμπληρώστε τα στη «Σύνδεση registrar».'
            );
        }

        $response = Http::acceptJson()
            ->timeout(30)
            ->post($this->baseUrl($credentials).'/v1beta/auth/login', [
                'username' => (string) $credentials->get('username'),
                'password' => (string) $credentials->get('password'),
            ]);

        if ($response->failed()) {
            $desc = $response->json('desc');

            throw new RuntimeException(
                'Openprovider login '.$response->status().': '.(is_scalar($desc) ? (string) $desc : 'αποτυχία σύνδεσης')
            );
        }

        $token = $response->json('data.token');

        return is_string($token) && $token !== '' ? $token : null;
    }

    private function baseUrl(DomainRegistrarCredentials $credentials): string
    {
        return $credentials->sandbox ? self::SANDBOX_URL : self::PRODUCTION_URL;
    }

    private function tokenCacheKey(DomainRegistrarCredentials $credentials): string
    {
        // Keyed by endpoint × username — never the password (no secret in a key).
        return 'domains:op:token:'.md5($this->baseUrl($credentials).'|'.(string) $credentials->get('username'));
    }

    /** @return array{0: string, 1: string} name + extension for the OP payload shape. */
    private function splitFqdn(string $fqdn): array
    {
        $fqdn = mb_strtolower(trim($fqdn));
        $dot = mb_strpos($fqdn, '.');
        if ($dot === false || $dot === 0) {
            throw new RuntimeException('Μη έγκυρο όνομα domain: '.$fqdn);
        }

        return [mb_substr($fqdn, 0, $dot), mb_substr($fqdn, $dot + 1)];
    }
}
