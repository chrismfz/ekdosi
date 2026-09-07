<?php

namespace Tests\Feature\Domains;

use App\Enums\DomainStatus;
use App\Models\Company;
use App\Models\Domain;
use App\Models\DomainRegistrarConnection;
use App\Models\DomainTld;
use App\Services\Domains\DomainSyncService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Πυλώνας A / A2b — the registrar-truth pull applied to the row: expiry/NS/
 * registrar-id/status updates, sync bookkeeping (last_synced_at/sync_error),
 * routing via the domain's or the TLD's connection, and the never-silent
 * failure path. All against mocked HTTP (no live registrar calls in CI).
 */
class DomainSyncTest extends TestCase
{
    use RefreshDatabase;

    private const SANDBOX = 'http://api.sandbox.openprovider.nl:8480';

    private Company $company;

    private DomainTld $tld;

    private DomainRegistrarConnection $connection;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        // No live registrar calls in CI, EVER — an unmatched URL fails loudly
        // instead of escaping to the real network (caught one already: after
        // the id is adopted, the next sync hits /domains/{id}, not ?full_name=).
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
        // Routing via the TLD default (the domain carries no own connection).
        $this->tld = DomainTld::create([
            'company_id' => $this->company->id, 'tld' => 'gr',
            'registrar_connection_id' => $this->connection->id,
        ]);
    }

    private function domain(array $extra = []): Domain
    {
        return Domain::create(array_merge([
            'company_id' => $this->company->id,
            'domain_tld_id' => $this->tld->id,
            'sld' => 'example', 'tld' => 'gr', 'fqdn' => 'example.gr',
            'expires_at' => '2026-01-01',
        ], $extra));
    }

    public function test_sync_applies_expiry_status_ns_and_adopts_the_registrar_id(): void
    {
        Http::fake([
            self::SANDBOX.'/v1beta/auth/login' => Http::response(['data' => ['token' => 'tok']]),
            self::SANDBOX.'/v1beta/domains?full_name=example.gr' => Http::response(['data' => ['results' => [[
                'id' => 12345,
                'status' => 'ACT',
                'expiration_date' => '2027-06-15 00:00:00',
                'name_servers' => [['name' => 'NS1.Myip.GR'], ['name' => 'ns2.myip.gr']],
            ]]]]),
        ]);

        $domain = $this->domain(['status' => 'pending_register']);
        app(DomainSyncService::class)->sync($domain);
        $domain->refresh();

        $this->assertSame('2027-06-15', $domain->expires_at->toDateString());
        $this->assertSame('12345', $domain->registrar_domain_id);
        $this->assertSame(DomainStatus::Active, $domain->status);
        $this->assertSame('ACT', $domain->module_meta['registrar_status']);
        $this->assertNotNull($domain->last_synced_at);
        $this->assertNull($domain->sync_error);
        $this->assertSame(['ns1.myip.gr', 'ns2.myip.gr'], $domain->nameservers()->pluck('host')->all());
    }

    public function test_unknown_registrar_status_keeps_the_local_status(): void
    {
        Http::fake([
            self::SANDBOX.'/v1beta/auth/login' => Http::response(['data' => ['token' => 'tok']]),
            self::SANDBOX.'/v1beta/domains?full_name=example.gr' => Http::response(['data' => ['results' => [[
                'id' => 1, 'status' => 'SCH', 'expiration_date' => '2027-01-01 00:00:00', 'name_servers' => [],
            ]]]]),
        ]);

        $domain = $this->domain(['status' => 'grace']);
        app(DomainSyncService::class)->sync($domain);
        $domain->refresh();

        $this->assertSame(DomainStatus::Grace, $domain->status, 'άγνωστο OP status δεν αλλάζει το τοπικό');
        $this->assertSame('SCH', $domain->module_meta['registrar_status']);
    }

    public function test_a_failed_pull_is_recorded_on_the_row_and_rethrown(): void
    {
        // Same URL, first pull fails, second succeeds (a later Http::fake does
        // NOT supersede an earlier wildcard stub — sequence on one URL instead).
        Http::fake([
            self::SANDBOX.'/v1beta/auth/login' => Http::response(['data' => ['token' => 'tok']]),
            self::SANDBOX.'/v1beta/domains?full_name=example.gr' => Http::sequence()
                ->push(['desc' => 'boom'], 500)
                ->push(['data' => ['results' => [[
                    'id' => 7, 'status' => 'ACT', 'expiration_date' => '2027-01-01 00:00:00', 'name_servers' => [],
                ]]]]),
        ]);

        $domain = $this->domain();
        try {
            app(DomainSyncService::class)->sync($domain);
            $this->fail('έπρεπε να ρίξει');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('boom', $e->getMessage());
        }

        $domain->refresh();
        $this->assertNotNull($domain->last_synced_at);
        $this->assertStringContainsString('boom', (string) $domain->sync_error);

        // A later success clears the error.
        app(DomainSyncService::class)->sync($domain);
        $this->assertNull($domain->refresh()->sync_error);
    }

    public function test_manual_or_unrouted_domains_are_not_syncable(): void
    {
        $unrouted = Domain::create([
            'company_id' => $this->company->id,
            'domain_tld_id' => DomainTld::create(['company_id' => $this->company->id, 'tld' => 'com'])->id,
            'sld' => 'loose', 'tld' => 'com', 'fqdn' => 'loose.com',
        ]);

        $service = app(DomainSyncService::class);
        $this->assertFalse($service->isSyncable($unrouted));
        $this->assertTrue($service->isSyncable($this->domain()));
    }

    public function test_the_command_syncs_enabled_tenants_and_reports_failures(): void
    {
        // Run 1 resolves by name and ADOPTS id 9; run 2 therefore fetches
        // /domains/9 directly and finds the registrar down → exit 1, error on
        // the row, never a crash.
        Http::fake([
            self::SANDBOX.'/v1beta/auth/login' => Http::response(['data' => ['token' => 'tok']]),
            self::SANDBOX.'/v1beta/domains?full_name=example.gr' => Http::response(['data' => ['results' => [[
                'id' => 9, 'status' => 'ACT', 'expiration_date' => '2028-02-02 00:00:00', 'name_servers' => [],
            ]]]]),
            self::SANDBOX.'/v1beta/domains/9' => Http::response(['desc' => 'down'], 500),
        ]);

        $domain = $this->domain();
        $this->artisan('domains:sync', ['--tenant' => $this->company->slug])
            ->assertExitCode(0);
        $this->assertSame('2028-02-02', $domain->refresh()->expires_at->toDateString());

        $this->artisan('domains:sync', ['--tenant' => $this->company->slug])
            ->assertExitCode(1);
        $this->assertStringContainsString('down', (string) $domain->refresh()->sync_error);
    }
}
