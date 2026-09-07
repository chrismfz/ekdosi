<?php

namespace App\Services\Domains\Registrars;

use App\Contracts\DomainRegistrar;
use App\Services\Domains\DomainRegistrarNotConfigured;
use App\Support\Domains\AvailabilityResult;
use App\Support\Domains\DomainRegistrarCapabilities;
use App\Support\Domains\DomainRegistrarCredentials;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
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

        return new AvailabilityResult(
            fqdn: $fqdn,
            available: $status === 'free',
            // 'active' = taken; the API also returns per-name reasons/premium info.
            reason: $status === 'free' ? null : ($result['reason'] ?? $status ?: null),
        );
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
            throw new RuntimeException(
                'Openprovider error '.$response->status().': '.((string) ($response->json('desc') ?? $response->body()))
            );
        }

        return $response;
    }

    private function send(DomainRegistrarCredentials $credentials, string $token, string $method, string $path, array $payload): Response
    {
        return Http::withToken($token)
            ->acceptJson()
            ->timeout(30)
            ->send($method, $this->baseUrl($credentials).$path, ['json' => $payload]);
    }

    /** Cached bearer (6h) — Openprovider tokens live ~24h; 401 mid-flight re-logins. */
    private function token(DomainRegistrarCredentials $credentials): string
    {
        $token = Cache::remember(
            $this->tokenCacheKey($credentials),
            self::TOKEN_TTL_SECONDS,
            fn (): ?string => $this->freshToken($credentials),
        );

        if (! is_string($token) || $token === '') {
            Cache::forget($this->tokenCacheKey($credentials));

            throw new RuntimeException('Αποτυχία σύνδεσης στο Openprovider (login δεν επέστρεψε token).');
        }

        return $token;
    }

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
            return null;
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
