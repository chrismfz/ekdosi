<?php

namespace Tests\Feature\Domains;

use App\Models\Company;
use App\Models\DomainRegistrarConnection;
use App\Models\DomainTld;
use App\Models\DomainTldPrice;
use App\Services\Domains\DomainPricingSyncService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Πυλώνας A / A2c — the registrar COST pull into domain_tld_prices. The money
 * discipline under test: ONLY the `cost` column moves; sell price/is_enabled
 * are never touched, and synced-in rows are born disabled + unpriced so they
 * can never be billed. All against mocked HTTP (no live registrar calls in CI).
 */
class DomainPricingSyncTest extends TestCase
{
    use RefreshDatabase;

    private const SANDBOX = 'http://api.sandbox.openprovider.nl:8480';

    private Company $company;

    private DomainRegistrarConnection $connection;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        Http::preventStrayRequests();

        $this->company = Company::create([
            'name' => 'Dom', 'slug' => 'd-'.uniqid(), 'country_code' => 'GR',
            'einvoice_provider' => 'gr-mydata', 'mydata_mode' => 'off',
            'enable_domain_management' => true,
        ]);
        $this->connection = DomainRegistrarConnection::create([
            'company_id' => $this->company->id,
            'registrar' => 'openprovider',
            'is_active' => true,
            'mode' => 'sandbox',
            'config' => ['username' => 'myip', 'password' => 'secret'],
        ]);
    }

    private function tld(array $extra = []): DomainTld
    {
        return DomainTld::create(array_merge([
            'company_id' => $this->company->id,
            'tld' => 'eu',
            'registrar_connection_id' => $this->connection->id,
            'min_years' => 1,
            'is_active' => true,
        ], $extra));
    }

    private function fakePricing(string $tld = 'eu'): void
    {
        Http::fake([
            self::SANDBOX.'/v1beta/auth/login' => Http::response(['data' => ['token' => 'tok']]),
            self::SANDBOX.'/v1beta/tlds/'.$tld.'?with_price=true' => Http::response(['data' => ['prices' => [
                'create_price' => [
                    'product' => ['currency' => 'USD', 'price' => 99.0], // registry-facing — must lose to reseller
                    'reseller' => ['currency' => 'EUR', 'price' => 6.5],
                ],
                'renew_price' => ['reseller' => ['currency' => 'EUR', 'price' => 7.25]],
                'transfer_price' => ['reseller' => ['currency' => 'EUR', 'price' => 6.5]],
                'restore_price' => ['reseller' => ['currency' => 'EUR', 'price' => 40.0]],
            ]]]),
        ]);
    }

    public function test_sync_creates_missing_rows_disabled_and_unpriced(): void
    {
        $this->fakePricing();
        $tld = $this->tld();

        $counts = app(DomainPricingSyncService::class)->sync($tld);

        $this->assertSame(['updated' => 0, 'created' => 4, 'currency_mismatches' => []], $counts);
        $renewal = $tld->prices()->where('operation', 'renewal')->first();
        $this->assertSame('7.25', $renewal->cost);
        $this->assertNull($renewal->price, 'a synced-in row must never carry a sell price');
        $this->assertFalse($renewal->is_enabled, 'a synced-in row must be born unbillable');
        $this->assertSame(1, $renewal->years);
        $this->assertSame('EUR', $renewal->currency);
        // reseller (what WE pay) is the ONLY cost source — never the
        // registry-facing product block (a different kind of price).
        $this->assertSame('6.50', $tld->prices()->where('operation', 'register')->first()->cost);
    }

    public function test_a_product_only_price_block_is_skipped_never_recorded_as_cost(): void
    {
        Http::fake([
            self::SANDBOX.'/v1beta/auth/login' => Http::response(['data' => ['token' => 'tok']]),
            self::SANDBOX.'/v1beta/tlds/eu?with_price=true' => Http::response(['data' => ['prices' => [
                'create_price' => ['product' => ['currency' => 'USD', 'price' => 99.0]], // no reseller quote
                'renew_price' => ['reseller' => ['currency' => 'EUR', 'price' => 7.25]],
            ]]]),
        ]);
        $tld = $this->tld();

        $counts = app(DomainPricingSyncService::class)->sync($tld);

        $this->assertSame(1, $counts['created'], 'only the reseller-quoted operation lands');
        $this->assertNull($tld->prices()->where('operation', 'register')->first(), 'product price is not our cost');
    }

    public function test_a_no_change_rerun_reports_zero_updates(): void
    {
        $this->fakePricing();
        $tld = $this->tld();
        $sync = app(DomainPricingSyncService::class);

        $sync->sync($tld);
        $counts = $sync->sync($tld);

        $this->assertSame(['updated' => 0, 'created' => 0, 'currency_mismatches' => []], $counts);
    }

    public function test_a_quote_in_another_currency_than_an_existing_row_is_flagged(): void
    {
        Http::fake([
            self::SANDBOX.'/v1beta/auth/login' => Http::response(['data' => ['token' => 'tok']]),
            self::SANDBOX.'/v1beta/tlds/eu?with_price=true' => Http::response(['data' => ['prices' => [
                'renew_price' => ['reseller' => ['currency' => 'USD', 'price' => 8.0]],
            ]]]),
        ]);
        $tld = $this->tld();
        DomainTldPrice::create([
            'company_id' => $this->company->id, 'domain_tld_id' => $tld->id,
            'operation' => 'renewal', 'years' => 1, 'currency' => 'EUR',
            'cost' => 5.00, 'price' => 14.00, 'is_enabled' => true,
        ]);

        $counts = app(DomainPricingSyncService::class)->sync($tld);

        $this->assertSame(['renewal → USD'], $counts['currency_mismatches']);
        // the EUR row the operator prices against was NOT silently touched
        $this->assertSame('5.00', $tld->prices()->where('currency', 'EUR')->sole()->cost);
        // the USD quote still lands, disabled + unpriced
        $usd = $tld->prices()->where('currency', 'USD')->sole();
        $this->assertSame('8.00', $usd->cost);
        $this->assertFalse($usd->is_enabled);
    }

    public function test_sync_updates_only_the_cost_on_an_existing_row(): void
    {
        $this->fakePricing();
        $tld = $this->tld();
        DomainTldPrice::create([
            'company_id' => $this->company->id, 'domain_tld_id' => $tld->id,
            'operation' => 'renewal', 'years' => 1, 'currency' => 'EUR',
            'cost' => 5.00, 'price' => 14.00, 'is_enabled' => true,
        ]);

        $counts = app(DomainPricingSyncService::class)->sync($tld);

        $this->assertSame(['updated' => 1, 'created' => 3, 'currency_mismatches' => []], $counts);
        $row = $tld->prices()->where('operation', 'renewal')->first();
        $this->assertSame('7.25', $row->cost);
        $this->assertSame('14.00', $row->price, 'the operator sell price must never move');
        $this->assertTrue($row->is_enabled, 'is_enabled must never move');
    }

    public function test_costs_land_on_the_tld_minimum_term_row(): void
    {
        // .gr sells biennial only (min_years=2) — the registrar quotes the
        // minimum registrable period, so the cost belongs on the years=2 row.
        Http::fake([
            self::SANDBOX.'/v1beta/auth/login' => Http::response(['data' => ['token' => 'tok']]),
            self::SANDBOX.'/v1beta/tlds/gr?with_price=true' => Http::response(['data' => ['prices' => [
                'renew_price' => ['reseller' => ['currency' => 'EUR', 'price' => 12.0]],
            ]]]),
        ]);
        $tld = $this->tld(['tld' => 'gr', 'min_years' => 2]);

        app(DomainPricingSyncService::class)->sync($tld);

        $row = $tld->prices()->sole();
        $this->assertSame(2, $row->years);
        $this->assertSame('12.00', $row->cost);
    }

    public function test_manual_route_inactive_tld_or_unusable_connection_are_not_syncable(): void
    {
        $sync = app(DomainPricingSyncService::class);

        $manual = DomainRegistrarConnection::create([
            'company_id' => $this->company->id, 'registrar' => 'manual',
            'is_active' => true, 'mode' => 'production', 'config' => [],
        ]);
        $this->assertFalse($sync->isSyncable($this->tld(['tld' => 'a1', 'registrar_connection_id' => $manual->id])));

        $this->assertFalse($sync->isSyncable($this->tld(['tld' => 'a2', 'is_active' => false])));

        $off = DomainRegistrarConnection::create([
            'company_id' => $this->company->id, 'registrar' => 'openprovider',
            'is_active' => false, 'mode' => 'sandbox', 'config' => ['username' => 'u', 'password' => 'p'],
        ]);
        $this->assertFalse($sync->isSyncable($this->tld(['tld' => 'a3', 'registrar_connection_id' => $off->id])));

        $this->assertFalse($sync->isSyncable($this->tld(['tld' => 'a4', 'registrar_connection_id' => null])));

        $this->assertTrue($sync->isSyncable($this->tld(['tld' => 'a5'])));
    }

    public function test_command_syncs_and_skips_per_tenant(): void
    {
        $this->fakePricing();
        $this->tld();
        $manual = DomainRegistrarConnection::create([
            'company_id' => $this->company->id, 'registrar' => 'manual',
            'is_active' => true, 'mode' => 'production', 'config' => [],
        ]);
        $this->tld(['tld' => 'org', 'registrar_connection_id' => $manual->id]);

        $this->artisan('domains:sync-pricing', ['--tenant' => $this->company->slug])
            ->expectsOutputToContain('1 TLDs, 0 κόστη ενημερώθηκαν, 4 νέες cost-only γραμμές')
            ->assertExitCode(0);
    }

    public function test_command_fails_loudly_on_unknown_tenant_or_unknown_tld(): void
    {
        $this->artisan('domains:sync-pricing', ['--tenant' => 'nope'])->assertExitCode(1);

        $this->tld();
        $this->artisan('domains:sync-pricing', ['--tenant' => $this->company->slug, '--tld' => '.nosuch'])
            ->assertExitCode(1);
    }

    public function test_an_explicit_tld_that_only_matches_unsyncable_rows_fails_loudly(): void
    {
        // The operator asked for a pull that CANNOT happen (manual route) —
        // a silent success here is the exact no-op the flag guards against.
        $manual = DomainRegistrarConnection::create([
            'company_id' => $this->company->id, 'registrar' => 'manual',
            'is_active' => true, 'mode' => 'production', 'config' => [],
        ]);
        $this->tld(['tld' => 'gr', 'registrar_connection_id' => $manual->id, 'min_years' => 2]);

        $this->artisan('domains:sync-pricing', ['--tenant' => $this->company->slug, '--tld' => 'gr'])
            ->assertExitCode(1);
    }

    public function test_an_explicit_tld_with_no_domain_enabled_companies_fails_loudly(): void
    {
        $this->company->update(['enable_domain_management' => false]);

        $this->artisan('domains:sync-pricing', ['--tld' => 'eu'])->assertExitCode(1);
    }

    public function test_an_api_failure_is_counted_and_the_run_continues(): void
    {
        Http::fake([
            self::SANDBOX.'/v1beta/auth/login' => Http::response(['data' => ['token' => 'tok']]),
            self::SANDBOX.'/v1beta/tlds/bad?with_price=true' => Http::response(['desc' => 'boom'], 500),
            self::SANDBOX.'/v1beta/tlds/eu?with_price=true' => Http::response(['data' => ['prices' => [
                'renew_price' => ['reseller' => ['currency' => 'EUR', 'price' => 7.25]],
            ]]]),
        ]);
        $this->tld(['tld' => 'bad']);
        $good = $this->tld();

        $this->artisan('domains:sync-pricing', ['--tenant' => $this->company->slug])
            ->assertExitCode(1);

        // the healthy TLD still got its cost despite the earlier failure
        $this->assertSame('7.25', $good->prices()->where('operation', 'renewal')->sole()->cost);
    }
}
