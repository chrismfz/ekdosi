<?php

namespace App\Filament\Pages\Concerns;

use Carbon\Carbon;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Cache;

/**
 * Keeps the last live-AADE fetch (myDATA console / E3 overview / expenses
 * console) per tenant, so navigating away and back doesn't throw away a heavy
 * pull. The page's fetch action overwrites the cache → it doubles as "refresh".
 *
 * The page lists which of its public props make up the fetch state via
 * cachedFetchProps(); rememberFetch() snapshots them on a successful run and
 * restoreFetch() (called from mount()) rehydrates them. A 12h TTL keeps a
 * stale snapshot from lingering forever; the timestamp is surfaced so the
 * operator knows how fresh it is.
 */
trait RemembersLastFetch
{
    /** Human "πριν από X" of the cached fetch, if any. */
    public ?string $fetchedAtHuman = null;

    /** @return list<string> public props to snapshot/restore */
    abstract protected function cachedFetchProps(): array;

    protected function rememberFetch(): void
    {
        $state = [];
        foreach ($this->cachedFetchProps() as $prop) {
            $state[$prop] = $this->{$prop};
        }

        static::putFetchState(Filament::getTenant()?->getKey(), $state);
        $this->fetchedAtHuman = now()->diffForHumans();
    }

    /**
     * Write a fetch snapshot for a tenant WITHOUT a page instance — so a CLI
     * refresh (the read-only expenses cron) or another page can seed the exact
     * cache this page restores on mount. Same key + shape as rememberFetch().
     *
     * @param  array<string, mixed>  $state  keyed by this page's cachedFetchProps()
     */
    public static function putFetchState(int|string|null $tenantKey, array $state): void
    {
        Cache::put(static::fetchCacheKeyFor($tenantKey), [
            'state' => $state,
            'at' => now()->toIso8601String(),
        ], now()->addHours(12));
    }

    /** Whole cached payload (['state' => …, 'at' => iso]) for a tenant, or null. */
    public static function lastFetchPayload(int|string|null $tenantKey): ?array
    {
        $cached = Cache::get(static::fetchCacheKeyFor($tenantKey));

        return is_array($cached) ? $cached : null;
    }

    /** When this page last fetched for the tenant (interactive OR cron), or null. */
    public static function lastFetchAt(int|string|null $tenantKey): ?Carbon
    {
        $payload = static::lastFetchPayload($tenantKey);

        return isset($payload['at']) ? Carbon::parse($payload['at']) : null;
    }

    /**
     * The cached prop state for the tenant (e.g. to read a count off the last
     * result without rebuilding it), or null.
     *
     * @return array<string, mixed>|null
     */
    public static function lastFetchState(int|string|null $tenantKey): ?array
    {
        return static::lastFetchPayload($tenantKey)['state'] ?? null;
    }

    protected function restoreFetch(): void
    {
        $cached = Cache::get($this->fetchCacheKey());
        if (! is_array($cached) || ! isset($cached['state']) || ! is_array($cached['state'])) {
            return;
        }

        foreach ($cached['state'] as $prop => $value) {
            $this->{$prop} = $value;
        }

        if (isset($cached['at'])) {
            $this->fetchedAtHuman = Carbon::parse($cached['at'])->diffForHumans();
        }
    }

    protected function fetchCacheKey(): string
    {
        return static::fetchCacheKeyFor(Filament::getTenant()?->getKey());
    }

    protected static function fetchCacheKeyFor(int|string|null $tenantKey): string
    {
        return 'mydata-fetch:'.class_basename(static::class).':'.($tenantKey ?? 'none');
    }

    /**
     * Operator-facing message for an AADE 429. Pulls the "try again in N
     * seconds" hint out of the rate-limit message when present, and reminds the
     * operator that whatever was already on screen is the last cached fetch.
     */
    protected function rateLimitMessage(string $raw): string
    {
        $seconds = preg_match('/(\d+)\s*second/i', $raw, $m) ? (int) $m[1] : null;
        $retry = $seconds !== null
            ? 'Δοκιμάστε ξανά σε ~'.$seconds.' δευτερόλεπτα.'
            : 'Δοκιμάστε ξανά σε λίγο.';

        $note = $this->result !== null
            ? ' Εμφανίζονται τα προηγούμενα αποθηκευμένα στοιχεία.'
            : '';

        return 'Το myDATA περιόρισε προσωρινά τα αιτήματα (rate limit). '.$retry.$note;
    }
}
