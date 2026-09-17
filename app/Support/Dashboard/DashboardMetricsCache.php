<?php

namespace App\Support\Dashboard;

use App\Models\Company;
use App\Services\Dashboard\DashboardMetrics;
use Closure;
use Illuminate\Support\Facades\Cache;

/**
 * Per-slice cache in front of {@see DashboardMetrics} for the «Αναφορές»
 * page. Each report widget is a SEPARATE (lazy) Livewire request that
 * used to `new DashboardMetrics($tenant)` and re-run its own aggregates —
 * nine widgets, no sharing, ~25s. This fronts each metric slice with a
 * cross-request cache so the work is computed once and every widget (and
 * every re-open / filter toggle) reads it back.
 *
 * House pattern (same as VatPictureCache + `mydata:refresh-vat-picture`):
 * the widget only READS a fresh-enough cache; `dashboard:warm-metrics`
 * pre-builds it on the scheduler so the operator rarely hits a cold slice.
 * Freshness is bounded by the TTL (config `ekdosi.dashboard.cache_ttl`);
 * the Reports «Ανανέωση» action bumps a per-tenant version key for an
 * instant, tenant-wide bust after issuing a document.
 *
 * Tenant safety: every key is namespaced by company_id and DashboardMetrics
 * already scopes every query by it — no ambient context needed (CLI warm +
 * web read behave identically).
 */
class DashboardMetricsCache
{
    /** Bump when a slice's SHAPE changes, to orphan stale-shaped entries. */
    private const SCHEMA = 1;

    public function __construct(private readonly Company $tenant) {}

    public static function for(Company $tenant): self
    {
        return new self($tenant);
    }

    // ---- cached slices (the exact calls the Reports widgets make) --------
    //
    // Each takes an optional $fresh: widgets read (false), the scheduled warm
    // FORCE-rebuilds (true) so a run picks up newly-issued documents instead of
    // re-affirming a stale hit.

    /** @return array<string, mixed> */
    public function kpiSummary(int $year, bool $fresh = false): array
    {
        return $this->remember('kpi', [$year], fn (DashboardMetrics $m) => $m->kpiSummary($year), $fresh);
    }

    /** @return list<array{month: int, net: float, vat: float}> */
    public function monthlyForYear(int $year, bool $fresh = false): array
    {
        return $this->remember('monthly', [$year], fn (DashboardMetrics $m) => $m->monthlyForYear($year), $fresh);
    }

    /** @return list<float> */
    public function receiptsByMonth(int $year, bool $fresh = false): array
    {
        return $this->remember('receipts', [$year], fn (DashboardMetrics $m) => $m->receiptsByMonth($year), $fresh);
    }

    /** @return list<float> */
    public function cumulativeNetByMonth(int $year, bool $fresh = false): array
    {
        return $this->remember('cumulative', [$year], fn (DashboardMetrics $m) => $m->cumulativeNetByMonth($year), $fresh);
    }

    /** @return list<array{year: int, net: float, gross: float, vat: float, count: int}> */
    public function yearlyTotals(int $count, int $endYear, bool $fresh = false): array
    {
        return $this->remember('yearly', [$count, $endYear], fn (DashboardMetrics $m) => $m->yearlyTotals($count, $endYear), $fresh);
    }

    /** @return array<string, mixed> */
    public function vatByRateByQuarter(int $year, bool $fresh = false): array
    {
        return $this->remember('vat-quarter', [$year], fn (DashboardMetrics $m) => $m->vatByRateByQuarter($year), $fresh);
    }

    /** @return array<string, mixed> */
    public function seasonalProfile(int $years, int $endYear, bool $fresh = false): array
    {
        return $this->remember('seasonal', [$years, $endYear], fn (DashboardMetrics $m) => $m->seasonalProfile($years, $endYear), $fresh);
    }

    /** @return array<string, mixed> */
    public function projectNextYear(int $historyYears = 3, bool $fresh = false): array
    {
        return $this->remember('projection', [$historyYears], fn (DashboardMetrics $m) => $m->projectNextYear($historyYears), $fresh);
    }

    /** @return array<string, mixed> */
    public function netByMonthMatrix(int $years, int $endYear, bool $fresh = false): array
    {
        return $this->remember('heatmap', [$years, $endYear], fn (DashboardMetrics $m) => $m->netByMonthMatrix($years, $endYear), $fresh);
    }

    /**
     * Force-rebuild every slice a Reports page open needs for one focus year
     * (the widgets' exact calls) — the scheduled-warm entry point. Called per
     * (currentYear, previousYear) so the default view and one year back are hot.
     * projectNextYear is now-anchored, so the caller warms it once, not per year.
     */
    public function warm(int $year): void
    {
        $this->kpiSummary($year, true);
        $this->monthlyForYear($year, true);
        $this->receiptsByMonth($year, true);
        $this->cumulativeNetByMonth($year, true);
        $this->yearlyTotals(5, $year, true);
        $this->vatByRateByQuarter($year, true);
        $this->seasonalProfile(3, $year - 1, true);
        $this->netByMonthMatrix(4, $year, true);
    }

    // ---- cache mechanics ------------------------------------------------

    /**
     * Read a slice from cache, computing (once) on a miss. The builder gets a
     * fresh DashboardMetrics — instantiated only when it actually runs, so a
     * warm read touches neither the service nor the DB. With $fresh the value is
     * recomputed and overwritten in place (same key + version) — the scheduled
     * warm path, so it refreshes without a version bump (no cold window on the
     * web while it rebuilds).
     *
     * @param  list<mixed>  $args
     * @param  Closure(DashboardMetrics): mixed  $build
     */
    private function remember(string $slice, array $args, Closure $build, bool $fresh = false): mixed
    {
        $key = self::key($this->tenant->getKey(), $this->version(), $slice, $args);

        if ($fresh) {
            $value = $build(new DashboardMetrics($this->tenant));
            Cache::put($key, $value, self::ttl());

            return $value;
        }

        return Cache::remember($key, self::ttl(), fn () => $build(new DashboardMetrics($this->tenant)));
    }

    /**
     * The tenant's current cache generation. Every slice key embeds it, so a
     * single increment (see {@see bump}) invalidates ALL of this tenant's
     * slices at once without enumerating keys.
     */
    public function version(): int
    {
        return (int) (Cache::get(self::versionKey($this->tenant->getKey())) ?? 1);
    }

    /**
     * Invalidate every cached slice for a tenant (the Reports «Ανανέωση»
     * action) by rotating its generation. Stale entries expire via TTL.
     */
    public static function bump(Company $tenant): void
    {
        $key = self::versionKey($tenant->getKey());
        // Seed an integer so increment() has something to bump (a fresh cache
        // or one where the version key expired starts at generation 1).
        if (! Cache::has($key)) {
            Cache::forever($key, 1);
        }
        Cache::increment($key);
    }

    private static function ttl(): int
    {
        return (int) config('ekdosi.dashboard.cache_ttl', 10800);
    }

    /** @param list<mixed> $args */
    public static function key(int|string $companyId, int $version, string $slice, array $args): string
    {
        return sprintf(
            'dashboard-metrics:s%d:%s:v%d:%s:%s',
            self::SCHEMA,
            $companyId,
            $version,
            $slice,
            md5(serialize($args)),
        );
    }

    private static function versionKey(int|string $companyId): string
    {
        return "dashboard-metrics:ver:{$companyId}";
    }
}
