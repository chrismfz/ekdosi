<?php

namespace Tests\Feature\Domains;

use App\Enums\DomainStatus;
use App\Models\Company;
use App\Models\Customer;
use App\Models\Domain;
use App\Models\DomainRegistrarConnection;
use App\Models\DomainTld;
use App\Services\Domains\DomainImportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Πυλώνας A / A2c — the registrar-first import (README §9): the account's own
 * portfolio lands as ΑΔΕΣΠΟΤΑ domains with contacts (the manual-assign aid),
 * existing rows get registrar truth ONLY (never customer_id/auto_renew),
 * tombstones and operator-deleted TLDs are never resurrected. All against
 * mocked HTTP (no live registrar calls in CI, ever).
 */
class DomainRegistrarImportTest extends TestCase
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
            'label' => 'OP test',
            'is_active' => true,
            'mode' => 'sandbox',
            'config' => ['username' => 'myip', 'password' => 'secret'],
        ]);
    }

    /** @return array<string, mixed> one OP list record */
    private function opRecord(string $sld, string $tld, array $extra = []): array
    {
        return array_merge([
            'id' => crc32($sld.$tld),
            'domain' => ['name' => $sld, 'extension' => $tld],
            'status' => 'ACT',
            'expiration_date' => '2027-03-01 00:00:00',
            'creation_date' => '2020-05-10 00:00:00',
            'name_servers' => [['name' => 'ns1.myip.gr'], ['name' => 'ns2.myip.gr']],
        ], $extra);
    }

    public function test_import_creates_unassigned_domains_with_contacts_tld_rules_and_truth(): void
    {
        Http::fake([
            self::SANDBOX.'/v1beta/auth/login' => Http::response(['data' => ['token' => 'tok']]),
            self::SANDBOX.'/v1beta/domains?limit=100&offset=0' => Http::response(['data' => [
                'results' => [
                    $this->opRecord('example', 'gr', [
                        'autorenew' => 'on',
                        'owner_handle' => 'AB1-GR',
                        'admin_handle' => 'AB1-GR', // shared handle → resolved ONCE
                        'tech_handle' => 'CD2-GR',
                    ]),
                    $this->opRecord('minimal', 'eu', ['status' => 'XYZ', 'name_servers' => []]),
                ],
                'total' => 2,
            ]]),
            self::SANDBOX.'/v1beta/customers/AB1-GR' => Http::response(['data' => [
                'name' => ['first_name' => 'Νίκος', 'last_name' => 'Παπαδόπουλος'],
                'company_name' => 'MyIP Ltd',
                'email' => 'nikos@myip.gr',
                'phone' => ['country_code' => '+30', 'area_code' => '210', 'subscriber_number' => '1234567'],
                'address' => ['street' => 'Οδός', 'number' => '12', 'zipcode' => '11111', 'city' => 'Αθήνα', 'country' => 'gr'],
            ]]),
            self::SANDBOX.'/v1beta/customers/CD2-GR' => Http::response(['data' => [
                'name' => ['first_name' => '', 'last_name' => ''],
                'company_name' => 'Tech Co',
                'email' => 'tech@myip.gr',
            ]]),
        ]);

        $counts = app(DomainImportService::class)->import($this->company, $this->connection);

        $this->assertSame(['created' => 2, 'updated' => 0, 'skipped' => 0, 'contacts' => 3], $counts);

        $example = Domain::where('fqdn', 'example.gr')->sole();
        $this->assertNull($example->customer_id, 'imported domains land ΑΔΕΣΠΟΤΑ');
        $this->assertSame(DomainStatus::Active, $example->status);
        $this->assertSame('2027-03-01', $example->expires_at->toDateString());
        $this->assertSame('2020-05-10', $example->registered_at->toDateString());
        $this->assertTrue($example->auto_renew, "OP autorenew 'on' maps to true on create");
        $this->assertSame($this->connection->id, $example->registrar_connection_id, 'routing pinned to the importing connection');
        $this->assertSame(['ns1.myip.gr', 'ns2.myip.gr'], $example->nameservers()->pluck('host')->all());
        $this->assertNotNull($example->last_synced_at);

        // contacts resolved from handles — the manual-assign aid
        $registrant = $example->contacts()->where('type', 'registrant')->sole();
        $this->assertSame('Νίκος Παπαδόπουλος', $registrant->name);
        $this->assertSame('MyIP Ltd', $registrant->org);
        $this->assertSame('nikos@myip.gr', $registrant->email);
        $this->assertSame('+302101234567', $registrant->phone);
        $this->assertSame('Οδός 12', $registrant->address1);
        $this->assertSame('GR', $registrant->country);
        $this->assertSame('AB1-GR', $registrant->registrar_contact_handle);
        $this->assertSame('Tech Co', $example->contacts()->where('type', 'tech')->sole()->name);
        $this->assertSame(3, $example->contacts()->count());

        // the shared handle was fetched exactly once
        Http::assertSentCount(4); // login + list + 2 distinct handles

        // TLD catalogue rows auto-created, routed to the importing connection
        $this->assertSame($this->connection->id, DomainTld::where('tld', 'gr')->sole()->registrar_connection_id);
        $this->assertSame($this->connection->id, DomainTld::where('tld', 'eu')->sole()->registrar_connection_id);

        // unknown OP status on the minimal row → local default kept
        $minimal = Domain::where('fqdn', 'minimal.eu')->sole();
        $this->assertSame(DomainStatus::Active, $minimal->status);
        $this->assertFalse($minimal->auto_renew, 'unreported autorenew defaults OFF (option β)');
        $this->assertSame('XYZ', $minimal->module_meta['registrar_status']);
    }

    public function test_existing_live_row_gets_registrar_truth_only(): void
    {
        $customer = Customer::create(['company_id' => $this->company->id, 'name' => 'Πελάτης']);
        $tld = DomainTld::create([
            'company_id' => $this->company->id, 'tld' => 'gr',
            'registrar_connection_id' => $this->connection->id, 'is_active' => true,
        ]);
        $existing = Domain::create([
            'company_id' => $this->company->id, 'domain_tld_id' => $tld->id,
            'customer_id' => $customer->id,
            'sld' => 'example', 'tld' => 'gr', 'fqdn' => 'example.gr',
            'expires_at' => '2026-01-01', 'auto_renew' => false, 'status' => 'active',
        ]);

        Http::fake([
            self::SANDBOX.'/v1beta/auth/login' => Http::response(['data' => ['token' => 'tok']]),
            self::SANDBOX.'/v1beta/domains?limit=100&offset=0' => Http::response(['data' => [
                'results' => [$this->opRecord('example', 'gr', [
                    'autorenew' => 'on',
                    'owner_handle' => 'AB1-GR', // must NOT be fetched — assigned domain
                ])],
                'total' => 1,
            ]]),
        ]);

        $counts = app(DomainImportService::class)->import($this->company, $this->connection);

        $this->assertSame(['created' => 0, 'updated' => 1, 'skipped' => 0, 'contacts' => 0], $counts);
        $existing->refresh();
        $this->assertSame('2027-03-01', $existing->expires_at->toDateString(), 'registrar truth lands');
        $this->assertSame($customer->id, $existing->customer_id, 'customer_id NEVER touched by import');
        $this->assertFalse($existing->auto_renew, 'auto_renew is create-only (option β — operator/assignment decision)');
        $this->assertSame('2020-05-10', $existing->registered_at->toDateString(), 'null registered_at backfilled');
        $this->assertSame(0, $existing->contacts()->count(), 'contacts are operator territory once assigned');
    }

    public function test_tombstones_and_operator_deleted_tlds_are_never_resurrected(): void
    {
        $tld = DomainTld::create([
            'company_id' => $this->company->id, 'tld' => 'gr',
            'registrar_connection_id' => $this->connection->id, 'is_active' => true,
        ]);
        $dead = Domain::create([
            'company_id' => $this->company->id, 'domain_tld_id' => $tld->id,
            'sld' => 'dead', 'tld' => 'gr', 'fqdn' => 'dead.gr', 'expires_at' => '2025-01-01',
        ]);
        $dead->delete();

        $deletedTld = DomainTld::create([
            'company_id' => $this->company->id, 'tld' => 'org',
            'registrar_connection_id' => $this->connection->id, 'is_active' => true,
        ]);
        $deletedTld->delete();

        Http::fake([
            self::SANDBOX.'/v1beta/auth/login' => Http::response(['data' => ['token' => 'tok']]),
            self::SANDBOX.'/v1beta/domains?limit=100&offset=0' => Http::response(['data' => [
                'results' => [
                    $this->opRecord('dead', 'gr'),
                    $this->opRecord('something', 'org'),
                ],
                'total' => 2,
            ]]),
        ]);

        $counts = app(DomainImportService::class)->import($this->company, $this->connection);

        $this->assertSame(['created' => 0, 'updated' => 0, 'skipped' => 2, 'contacts' => 0], $counts);
        $this->assertNotNull(Domain::withTrashed()->where('fqdn', 'dead.gr')->sole()->deleted_at, 'tombstone untouched');
        $this->assertSame('2025-01-01', Domain::withTrashed()->where('fqdn', 'dead.gr')->sole()->expires_at->toDateString());
        $this->assertNull(Domain::withTrashed()->where('fqdn', 'something.org')->first(), 'no row under a deleted TLD');
        $this->assertNotNull(DomainTld::withTrashed()->where('tld', 'org')->sole()->deleted_at, 'deleted TLD stays deleted');
    }

    public function test_a_broken_contact_handle_warns_and_continues(): void
    {
        Http::fake([
            self::SANDBOX.'/v1beta/auth/login' => Http::response(['data' => ['token' => 'tok']]),
            self::SANDBOX.'/v1beta/domains?limit=100&offset=0' => Http::response(['data' => [
                'results' => [$this->opRecord('example', 'gr', [
                    'owner_handle' => 'BROKEN-1',
                    'tech_handle' => 'GONE-404',
                ])],
                'total' => 1,
            ]]),
            self::SANDBOX.'/v1beta/customers/BROKEN-1' => Http::response(['desc' => 'boom'], 500),
            self::SANDBOX.'/v1beta/customers/GONE-404' => Http::response(['desc' => 'not found'], 404),
        ]);

        $warnings = [];
        $counts = app(DomainImportService::class)->import(
            $this->company,
            $this->connection,
            function (string $m) use (&$warnings): void {
                $warnings[] = $m;
            },
        );

        $this->assertSame(['created' => 1, 'updated' => 0, 'skipped' => 0, 'contacts' => 0], $counts);
        $this->assertNotNull(Domain::where('fqdn', 'example.gr')->first(), 'the domain still lands');
        $this->assertCount(1, $warnings, 'the 500 warns; the 404 is a quiet «no aid»');
        $this->assertStringContainsString('BROKEN-1', $warnings[0]);
    }

    public function test_pagination_walks_the_whole_account(): void
    {
        $page1 = [];
        for ($i = 0; $i < 100; $i++) {
            $page1[] = $this->opRecord('bulk'.$i, 'eu', ['name_servers' => []]);
        }
        $page2 = [];
        for ($i = 100; $i < 130; $i++) {
            $page2[] = $this->opRecord('bulk'.$i, 'eu', ['name_servers' => []]);
        }

        Http::fake([
            self::SANDBOX.'/v1beta/auth/login' => Http::response(['data' => ['token' => 'tok']]),
            self::SANDBOX.'/v1beta/domains?limit=100&offset=0' => Http::response(['data' => ['results' => $page1, 'total' => 130]]),
            self::SANDBOX.'/v1beta/domains?limit=100&offset=100' => Http::response(['data' => ['results' => $page2, 'total' => 130]]),
        ]);

        $counts = app(DomainImportService::class)->import($this->company, $this->connection);

        $this->assertSame(130, $counts['created']);
        $this->assertSame(130, Domain::where('company_id', $this->company->id)->count());
    }

    public function test_command_runs_and_fails_loudly_on_unknown_connection_or_none_eligible(): void
    {
        Http::fake([
            self::SANDBOX.'/v1beta/auth/login' => Http::response(['data' => ['token' => 'tok']]),
            self::SANDBOX.'/v1beta/domains?limit=100&offset=0' => Http::response(['data' => [
                'results' => [$this->opRecord('example', 'gr')], 'total' => 1,
            ]]),
        ]);

        $this->artisan('domains:import-registrar', ['--tenant' => $this->company->slug])
            ->expectsOutputToContain('1 νέα (αδέσποτα)')
            ->assertExitCode(0);

        $this->artisan('domains:import-registrar', ['--tenant' => $this->company->slug, '--connection' => '999999'])
            ->assertExitCode(1);

        // a tenant whose only connections are manual/off must not no-op green
        $this->connection->update(['is_active' => false]);
        $this->artisan('domains:import-registrar', ['--tenant' => $this->company->slug])
            ->assertExitCode(1);
    }
}
