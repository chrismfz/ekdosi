<?php

namespace Tests\Feature\Domains;

use App\Enums\DomainStatus;
use App\Models\Company;
use App\Models\Domain;
use App\Models\DomainContact;
use App\Models\DomainNameserver;
use App\Models\DomainRegistrarConnection;
use App\Models\DomainRegistrarLog;
use App\Models\DomainTld;
use App\Services\Domains\DomainManagementService;
use App\Services\Domains\DomainRegistrarNotConfigured;
use App\Services\Domains\DomainRenewalInProgress;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Tests\TestCase;

/**
 * Πυλώνας A / A3d — the management writes (NS/lock/privacy/contacts pushes +
 * the redemption restore): one PUT per operation through the shared write
 * skeleton, local mirrors follow the REGISTRAR success only, the restore is
 * sync-first (already-live = adopt, zero charge), and every leg audits. All
 * against mocked HTTP.
 */
class DomainManagementServiceTest extends TestCase
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
        Http::preventStrayRequests();

        $this->company = Company::create([
            'name' => 'Dom', 'slug' => 'd-'.uniqid(), 'country_code' => 'GR',
            'einvoice_provider' => 'gr-mydata', 'mydata_mode' => 'off',
            'enable_domain_management' => true,
        ]);
        $this->connection = DomainRegistrarConnection::create([
            'company_id' => $this->company->id, 'registrar' => 'openprovider',
            'is_active' => true, 'mode' => 'sandbox',
            'config' => ['username' => 'myip', 'password' => 'secret'],
        ]);
        $this->tld = DomainTld::create([
            'company_id' => $this->company->id, 'tld' => 'eu',
            'registrar_connection_id' => $this->connection->id, 'is_active' => true,
        ]);
    }

    private function activeDomain(array $extra = []): Domain
    {
        return Domain::create(array_merge([
            'company_id' => $this->company->id, 'domain_tld_id' => $this->tld->id,
            'sld' => 'managed', 'tld' => 'eu', 'fqdn' => 'managed.eu',
            'status' => DomainStatus::Active, 'registrar_domain_id' => '900',
            'expires_at' => '2027-03-01',
        ], $extra));
    }

    private function addNameservers(Domain $domain, array $hosts): void
    {
        foreach ($hosts as $host) {
            DomainNameserver::create([
                'company_id' => $this->company->id, 'domain_id' => $domain->id,
                'host' => $host,
            ]);
        }
    }

    public function test_push_nameservers_puts_the_full_local_set_and_audits(): void
    {
        Http::fake([
            self::SANDBOX.'/v1beta/auth/login' => Http::response(['data' => ['token' => 'tok']]),
            self::SANDBOX.'/v1beta/domains/900' => Http::sequence()
                ->push(['data' => ['id' => 900]]) // the PUT
                ->push(['data' => ['id' => 900, 'status' => 'ACT', 'expiration_date' => '2027-03-01 00:00:00',
                    'name_servers' => [['name' => 'ns1.myip.gr'], ['name' => 'ns2.myip.gr']]]]), // the re-fetch
        ]);
        $domain = $this->activeDomain();
        $this->addNameservers($domain, ['NS1.myip.gr', 'ns2.myip.gr', '  ']); // blank row must be filtered, host lowercased

        $log = app(DomainManagementService::class)->pushNameservers($domain);

        $this->assertSame(DomainRegistrarLog::STATUS_OK, $log->status);
        $this->assertSame('set_nameservers', $log->action);
        $this->assertSame(['ns1.myip.gr', 'ns2.myip.gr'], $log->request['nameservers']);
        Http::assertSent(fn ($req) => $req->method() === 'PUT'
            && str_ends_with($req->url(), '/v1beta/domains/900')
            && $req['name_servers'] === [['name' => 'ns1.myip.gr'], ['name' => 'ns2.myip.gr']]
            && ! array_key_exists('is_locked', $req->data())
            && ! array_key_exists('is_private_whois_enabled', $req->data()));
        // the re-fetched truth applied through the one apply path
        $this->assertSame(['ns1.myip.gr', 'ns2.myip.gr'], $domain->refresh()->nameservers()->orderBy('host')->pluck('host')->all());
    }

    public function test_push_nameservers_refuses_below_two_valid_hosts(): void
    {
        Http::fake();
        $domain = $this->activeDomain();
        $this->addNameservers($domain, ['ns1.myip.gr', '   ']);

        try {
            app(DomainManagementService::class)->pushNameservers($domain);
            $this->fail('needs ≥2 valid nameservers');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('2 συμπληρωμένους nameservers', $e->getMessage());
        }
        Http::assertNothingSent();
        $this->assertSame(DomainRegistrarLog::STATUS_FAILED, DomainRegistrarLog::where('action', 'set_nameservers')->sole()->status);
    }

    public function test_transfer_lock_mirrors_locally_only_after_the_registrar_accepted(): void
    {
        Http::fake([
            self::SANDBOX.'/v1beta/auth/login' => Http::response(['data' => ['token' => 'tok']]),
            self::SANDBOX.'/v1beta/domains/900' => Http::sequence()
                ->push(['data' => ['id' => 900]])
                ->push(['data' => ['id' => 900, 'status' => 'ACT', 'expiration_date' => '2027-03-01 00:00:00']]),
        ]);
        $domain = $this->activeDomain(['transfer_lock' => false]);

        $log = app(DomainManagementService::class)->setTransferLock($domain, true);

        $this->assertSame(DomainRegistrarLog::STATUS_OK, $log->status);
        $this->assertSame('set_lock', $log->action);
        $this->assertTrue($log->request['locked']);
        Http::assertSent(fn ($req) => $req->method() === 'PUT'
            && str_ends_with($req->url(), '/v1beta/domains/900')
            && $req['is_locked'] === true
            && ! array_key_exists('name_servers', $req->data()));
        $this->assertTrue($domain->refresh()->transfer_lock, 'local mirror follows the registrar success');
    }

    public function test_a_rejected_put_leaves_the_local_mirror_untouched(): void
    {
        Http::fake([
            self::SANDBOX.'/v1beta/auth/login' => Http::response(['data' => ['token' => 'tok']]),
            self::SANDBOX.'/v1beta/domains/900' => Http::response(['desc' => 'not allowed'], 400),
        ]);
        $domain = $this->activeDomain(['transfer_lock' => false]);

        try {
            app(DomainManagementService::class)->setTransferLock($domain, true);
            $this->fail('the registrar rejected the PUT');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('not allowed', $e->getMessage());
        }
        $this->assertFalse($domain->refresh()->transfer_lock, 'never an optimistic mirror');
        $this->assertSame(DomainRegistrarLog::STATUS_FAILED, DomainRegistrarLog::where('action', 'set_lock')->sole()->status);
    }

    public function test_whois_privacy_puts_and_mirrors(): void
    {
        Http::fake([
            self::SANDBOX.'/v1beta/auth/login' => Http::response(['data' => ['token' => 'tok']]),
            self::SANDBOX.'/v1beta/domains/900' => Http::sequence()
                ->push(['data' => ['id' => 900]])
                ->push(['data' => ['id' => 900, 'status' => 'ACT']]),
        ]);
        $domain = $this->activeDomain(['whois_privacy' => false]);

        $log = app(DomainManagementService::class)->setWhoisPrivacy($domain, true);

        $this->assertSame(DomainRegistrarLog::STATUS_OK, $log->status);
        Http::assertSent(fn ($req) => $req->method() === 'PUT' && $req['is_private_whois_enabled'] === true);
        $this->assertTrue($domain->refresh()->whois_privacy);
    }

    public function test_push_contacts_ensures_missing_handles_and_reassigns_them(): void
    {
        Http::fake([
            self::SANDBOX.'/v1beta/auth/login' => Http::response(['data' => ['token' => 'tok']]),
            self::SANDBOX.'/v1beta/customers' => Http::response(['data' => ['handle' => 'NEW1-EU']]),
            self::SANDBOX.'/v1beta/domains/900' => Http::sequence()
                ->push(['data' => ['id' => 900]])
                ->push(['data' => ['id' => 900, 'status' => 'ACT', 'owner_handle' => 'NEW1-EU']]),
        ]);
        $domain = $this->activeDomain();
        DomainContact::create([
            'company_id' => $this->company->id, 'domain_id' => $domain->id,
            'type' => 'registrant', 'name' => 'Νίκος Παπαδόπουλος', 'email' => 'n@myip.gr',
        ]);

        $log = app(DomainManagementService::class)->pushContacts($domain);

        $this->assertSame(DomainRegistrarLog::STATUS_OK, $log->status);
        $this->assertSame('set_contacts', $log->action);
        Http::assertSent(fn ($req) => $req->method() === 'PUT'
            && str_ends_with($req->url(), '/v1beta/domains/900')
            && $req['owner_handle'] === 'NEW1-EU'
            && $req['admin_handle'] === 'NEW1-EU'); // fallback to registrant
        $this->assertSame('NEW1-EU', $domain->contacts()->where('type', 'registrant')->sole()->registrar_contact_handle);
    }

    public function test_push_contacts_refuses_without_a_registrant_email(): void
    {
        Http::fake();
        $domain = $this->activeDomain();

        try {
            app(DomainManagementService::class)->pushContacts($domain);
            $this->fail('registrant with email required');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('registrant', $e->getMessage());
        }
        Http::assertNothingSent();
    }

    public function test_management_refusals_wrong_status_manual_cross_tenant_and_lock(): void
    {
        $service = app(DomainManagementService::class);
        Http::fake();

        // pending_register: no registrar object to manage yet
        $pending = $this->activeDomain(['sld' => 'p1', 'fqdn' => 'p1.eu', 'status' => DomainStatus::PendingRegister, 'registrar_domain_id' => null]);
        $this->addNameservers($pending, ['ns1.myip.gr', 'ns2.myip.gr']);
        try {
            $service->pushNameservers($pending);
            $this->fail('pending rows are not manageable');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('ενεργά/ληγμένα', $e->getMessage());
        }

        // manual routing refuses with the portal advice
        $manual = DomainRegistrarConnection::create([
            'company_id' => $this->company->id, 'registrar' => 'manual',
            'is_active' => true, 'mode' => 'production', 'config' => [],
        ]);
        $manualDomain = $this->activeDomain(['sld' => 'p2', 'fqdn' => 'p2.eu', 'registrar_connection_id' => $manual->id]);
        try {
            $service->setTransferLock($manualDomain, true);
            $this->fail('manual refuses');
        } catch (DomainRegistrarNotConfigured) {
        }

        // cross-tenant claim on the shared reseller account
        $other = Company::create([
            'name' => 'Other', 'slug' => 'o-'.uniqid(), 'country_code' => 'GR',
            'einvoice_provider' => 'gr-mydata', 'mydata_mode' => 'off',
            'enable_domain_management' => true,
        ]);
        $otherTld = DomainTld::create(['company_id' => $other->id, 'tld' => 'eu', 'is_active' => true]);
        Domain::create([
            'company_id' => $other->id, 'domain_tld_id' => $otherTld->id,
            'sld' => 'p3', 'tld' => 'eu', 'fqdn' => 'p3.eu',
            'status' => 'active', 'registrar_domain_id' => '901',
        ]);
        $mine = $this->activeDomain(['sld' => 'p3', 'fqdn' => 'p3.eu']);
        try {
            $service->setWhoisPrivacy($mine, true);
            $this->fail('cross-tenant claim must refuse');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('ΑΛΛΗ εταιρεία', $e->getMessage());
        }

        // the shared 'manage' lock serializes ALL management ops per domain
        $locked = $this->activeDomain(['sld' => 'p4', 'fqdn' => 'p4.eu']);
        $lock = Cache::lock('domains:manage:'.$locked->id, 60);
        $this->assertTrue($lock->get());
        try {
            $service->setTransferLock($locked, true);
            $this->fail('lock refuses');
        } catch (DomainRenewalInProgress) {
        } finally {
            $lock->release();
        }

        Http::assertNothingSent();
        $this->assertSame(4, DomainRegistrarLog::where('status', DomainRegistrarLog::STATUS_FAILED)->count(), 'every refusal audits');
    }

    public function test_restore_fires_from_redemption_and_applies_the_fresh_truth(): void
    {
        Http::fake([
            self::SANDBOX.'/v1beta/auth/login' => Http::response(['data' => ['token' => 'tok']]),
            self::SANDBOX.'/v1beta/domains/900' => Http::sequence()
                ->push(['data' => ['id' => 900, 'status' => 'DEL']]) // the sync-first probe: genuinely dead
                ->push(['data' => ['id' => 900, 'status' => 'ACT', 'expiration_date' => '2027-06-01 00:00:00']]), // post-restore re-fetch
            self::SANDBOX.'/v1beta/domains/900/restore' => Http::response(['data' => []]),
        ]);
        $domain = $this->activeDomain(['status' => DomainStatus::Redemption]);

        $log = app(DomainManagementService::class)->restore($domain);

        $this->assertSame(DomainRegistrarLog::STATUS_OK, $log->status);
        $this->assertSame('restore', $log->action);
        Http::assertSent(fn ($req) => str_ends_with($req->url(), '/v1beta/domains/900/restore'));
        $domain->refresh();
        $this->assertSame(DomainStatus::Active, $domain->status);
        $this->assertSame('2027-06-01', $domain->expires_at->toDateString());
    }

    public function test_restore_adopts_an_already_live_record_without_charging(): void
    {
        // Restored at the registrar panel (or our DEL was stale): the probe
        // sees ACT — adopt the truth, never a restore fee.
        Http::fake([
            self::SANDBOX.'/v1beta/auth/login' => Http::response(['data' => ['token' => 'tok']]),
            self::SANDBOX.'/v1beta/domains/900' => Http::response(['data' => [
                'id' => 900, 'status' => 'ACT', 'expiration_date' => '2027-06-01 00:00:00',
            ]]),
        ]);
        $domain = $this->activeDomain(['status' => DomainStatus::Redemption]);

        $log = app(DomainManagementService::class)->restore($domain);

        $this->assertSame(DomainRegistrarLog::STATUS_ADOPTED, $log->status);
        Http::assertNotSent(fn ($req) => str_ends_with($req->url(), '/restore'));
        $this->assertSame(DomainStatus::Active, $domain->refresh()->status);
    }

    public function test_restore_refuses_outside_redemption_or_deleted(): void
    {
        Http::fake();
        $domain = $this->activeDomain(); // active

        try {
            app(DomainManagementService::class)->restore($domain);
            $this->fail('restore is redemption/deleted-only');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('redemption', $e->getMessage());
        }
        Http::assertNothingSent();
        $this->assertSame(DomainRegistrarLog::STATUS_FAILED, DomainRegistrarLog::where('action', 'restore')->sole()->status);
    }

    public function test_an_accepted_put_with_a_failed_refetch_still_logs_ok_and_mirrors(): void
    {
        // The PUT was accepted = the registrar state DID change; a re-fetch
        // hiccup must never surface it as a failure (the requested value
        // stands until the nightly sync confirms).
        Http::fake([
            self::SANDBOX.'/v1beta/auth/login' => Http::response(['data' => ['token' => 'tok']]),
            self::SANDBOX.'/v1beta/domains/900' => Http::sequence()
                ->push(['data' => ['id' => 900]]) // the PUT: accepted
                ->push(['desc' => 'boom'], 500), // the re-fetch: down
        ]);
        $domain = $this->activeDomain(['transfer_lock' => false]);

        $log = app(DomainManagementService::class)->setTransferLock($domain, true);

        $this->assertSame(DomainRegistrarLog::STATUS_OK, $log->status);
        $this->assertTrue($domain->refresh()->transfer_lock);
    }

    public function test_a_charged_restore_with_a_failed_refetch_still_logs_ok(): void
    {
        Http::fake([
            self::SANDBOX.'/v1beta/auth/login' => Http::response(['data' => ['token' => 'tok']]),
            self::SANDBOX.'/v1beta/domains/900' => Http::sequence()
                ->push(['data' => ['id' => 900, 'status' => 'DEL']]) // probe: dead
                ->push(['desc' => 'boom'], 500), // post-restore re-fetch: down
            self::SANDBOX.'/v1beta/domains/900/restore' => Http::response(['data' => []]),
        ]);
        $domain = $this->activeDomain(['status' => DomainStatus::Redemption]);

        $log = app(DomainManagementService::class)->restore($domain);

        $this->assertSame(DomainRegistrarLog::STATUS_OK, $log->status, 'the registrar HAS charged — never a failed log');
        $this->assertSame(DomainStatus::Redemption, $domain->refresh()->status, 'the nightly sync lands the promoted status');
    }

    public function test_the_registrar_truth_corrects_the_mirror_over_the_requested_value(): void
    {
        // The PUT is accepted but the re-fetched truth says the flag did NOT
        // take (e.g. a TLD without registry lock) — the registrar's answer
        // wins over the requested value, and the sync keeps correcting it.
        Http::fake([
            self::SANDBOX.'/v1beta/auth/login' => Http::response(['data' => ['token' => 'tok']]),
            self::SANDBOX.'/v1beta/domains/900' => Http::sequence()
                ->push(['data' => ['id' => 900]])
                ->push(['data' => ['id' => 900, 'status' => 'ACT', 'is_locked' => false]]),
        ]);
        $domain = $this->activeDomain(['transfer_lock' => false]);

        app(DomainManagementService::class)->setTransferLock($domain, true);

        $this->assertFalse($domain->refresh()->transfer_lock, 'registrar truth wins over the requested value');
    }

    public function test_restore_refuses_an_in_between_registrar_state(): void
    {
        // Neither adopt («επανήλθε» would be a lie) nor a restore fee on top
        // of an in-flight/unmapped record — refuse loudly, one audit row.
        Http::fake([
            self::SANDBOX.'/v1beta/auth/login' => Http::response(['data' => ['token' => 'tok']]),
            self::SANDBOX.'/v1beta/domains/900' => Http::response(['data' => ['id' => 900, 'status' => 'REQ']]),
        ]);
        $domain = $this->activeDomain(['status' => DomainStatus::Redemption]);

        try {
            app(DomainManagementService::class)->restore($domain);
            $this->fail('an in-between state must refuse');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('ενδιάμεση κατάσταση', $e->getMessage());
        }
        Http::assertNotSent(fn ($req) => str_ends_with($req->url(), '/restore'));
        $this->assertSame(DomainStatus::Redemption, $domain->refresh()->status, 'the probe never rewrites the row');
        $this->assertSame(DomainRegistrarLog::STATUS_FAILED, DomainRegistrarLog::where('action', 'restore')->sole()->status);
    }

    public function test_restore_refuses_when_the_name_left_the_account(): void
    {
        Http::fake([
            self::SANDBOX.'/v1beta/auth/login' => Http::response(['data' => ['token' => 'tok']]),
            self::SANDBOX.'/v1beta/domains/900' => Http::response(['desc' => 'not found'], 404),
            self::SANDBOX.'/v1beta/domains?full_name=managed.eu' => Http::response(['data' => ['results' => []]]),
        ]);
        $domain = $this->activeDomain(['status' => DomainStatus::Deleted]);

        try {
            app(DomainManagementService::class)->restore($domain);
            $this->fail('nothing to restore');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('δεν βρίσκεται πλέον', $e->getMessage());
        }
        Http::assertNotSent(fn ($req) => str_ends_with($req->url(), '/restore'));
        $this->assertSame(DomainRegistrarLog::STATUS_FAILED, DomainRegistrarLog::where('action', 'restore')->sole()->status);
    }
}
