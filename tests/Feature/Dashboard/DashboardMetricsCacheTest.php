<?php

namespace Tests\Feature\Dashboard;

use App\Models\Company;
use App\Models\Invoice;
use App\Models\InvoiceType;
use App\Support\Dashboard\DashboardMetricsCache;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/**
 * The per-slice cache in front of DashboardMetrics (#7): a slice is computed
 * once and read back, invalidated by a version bump («Ανανέωση») or force-
 * rebuilt by the scheduled warm — the three paths the Reports page relies on.
 */
class DashboardMetricsCacheTest extends TestCase
{
    use RefreshDatabase;

    private Company $tenant;

    private InvoiceType $type;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-05-15 12:00:00'));

        $this->tenant = Company::create([
            'name' => 'Cache', 'slug' => 'cache-'.uniqid(),
            'country_code' => 'GR', 'einvoice_provider' => 'gr-mydata', 'mydata_mode' => 'off',
        ]);
        $this->type = InvoiceType::create([
            'company_id' => $this->tenant->id, 'code' => 'TPY', 'name' => 'ΤΠΥ', 'invcount' => 0,
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function invoice(string $issuedAt, float $net, float $gross): void
    {
        Invoice::create([
            'company_id' => $this->tenant->id, 'invoice_type_id' => $this->type->id,
            'invcode' => 'TPY'.uniqid(), 'code' => random_int(1, 999999),
            'issued_at' => $issuedAt, 'net_total' => $net, 'gross_total' => $gross,
            'local_status' => 'active',
        ]);
    }

    public function test_a_slice_is_cached_and_ignores_new_data_until_invalidated(): void
    {
        $this->invoice('2026-03-01 10:00:00', 100, 124);
        $cache = DashboardMetricsCache::for($this->tenant);

        $before = $cache->monthlyForYear(2026);
        $this->assertEqualsWithDelta(100.0, $before[2]['net'], 0.01); // March = index 2

        // New sale AFTER the slice was cached — the cached read must not see it.
        $this->invoice('2026-03-20 10:00:00', 500, 620);
        $this->assertSame($before, $cache->monthlyForYear(2026), 'a cached slice ignores new data');
    }

    public function test_version_bump_invalidates_every_slice(): void
    {
        $this->invoice('2026-03-01 10:00:00', 100, 124);
        $cache = DashboardMetricsCache::for($this->tenant);
        $cache->monthlyForYear(2026); // seed

        $this->invoice('2026-03-20 10:00:00', 500, 620);
        DashboardMetricsCache::bump($this->tenant); // «Ανανέωση»

        $after = $cache->monthlyForYear(2026);
        $this->assertEqualsWithDelta(600.0, $after[2]['net'], 0.01); // 100 + 500 now visible
    }

    public function test_warm_force_rebuilds_a_slice_without_a_version_bump(): void
    {
        $this->invoice('2026-04-01 10:00:00', 100, 124);
        $cache = DashboardMetricsCache::for($this->tenant);
        $cache->monthlyForYear(2026); // seed the (soon stale) cache

        $this->invoice('2026-04-10 10:00:00', 50, 62);
        $cache->warm(2026); // scheduler path: force-rebuild in place

        // A normal (non-fresh) read now returns the warmed value — same version.
        $after = $cache->monthlyForYear(2026);
        $this->assertEqualsWithDelta(150.0, $after[3]['net'], 0.01); // April = index 3
    }

    public function test_warm_command_populates_the_cache(): void
    {
        $this->invoice('2026-02-01 10:00:00', 100, 124);
        $key = DashboardMetricsCache::key($this->tenant->id, 1, 'monthly', [2026]);

        $this->assertFalse(Cache::has($key), 'cold before warm');

        $this->artisan('dashboard:warm-metrics', ['--tenant' => $this->tenant->slug])
            ->assertSuccessful();

        $this->assertTrue(Cache::has($key), 'the current year is warmed');
    }
}
