<?php

namespace Tests\Feature\Domains;

use App\Enums\DomainStatus;
use App\Models\Company;
use App\Models\Domain;
use App\Models\DomainContact;
use App\Models\DomainRegistrarConnection;
use App\Models\DomainRegistrarLog;
use App\Models\DomainTld;
use App\Services\Domains\DomainRegistrarNotConfigured;
use App\Services\Domains\DomainRegistrationService;
use App\Services\Domains\DomainRenewalInProgress;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Tests\TestCase;

/**
 * Πυλώνας A / A3b — the register write, operator-gated, with the same §6.6
 * discipline as the renewal: availability FIRST (never register blind), a
 * taken-but-ours name ADOPTS (retry after a timeout-that-charged pays once),
 * a third-party name refuses loudly, and every attempt lands in the API
 * history. All against mocked HTTP.
 */
class DomainRegistrationServiceTest extends TestCase
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
            'registrar_connection_id' => $this->connection->id, 'is_active' => true, 'min_years' => 1,
        ]);
    }

    private function pendingDomain(array $extra = [], bool $withContact = true, int $nameservers = 2): Domain
    {
        $domain = Domain::create(array_merge([
            'company_id' => $this->company->id, 'domain_tld_id' => $this->tld->id,
            'sld' => 'fresh', 'tld' => 'eu', 'fqdn' => 'fresh.eu',
            'status' => DomainStatus::PendingRegister,
        ], $extra));
        if ($withContact) {
            DomainContact::create([
                'company_id' => $this->company->id, 'domain_id' => $domain->id,
                'type' => 'registrant', 'name' => 'Νίκος Παπαδόπουλος',
                'email' => 'nikos@myip.gr', 'phone' => '+302101234567',
                'address1' => 'Οδός 12', 'city' => 'Αθήνα', 'postcode' => '11111', 'country' => 'GR',
            ]);
        }
        for ($i = 1; $i <= $nameservers; $i++) {
            $domain->nameservers()->create([
                'company_id' => $this->company->id, 'host' => "ns{$i}.myip.gr", 'sort_order' => $i,
            ]);
        }

        return $domain;
    }

    public function test_register_creates_handles_fires_and_applies_the_truth(): void
    {
        Http::fake([
            self::SANDBOX.'/v1beta/auth/login' => Http::response(['data' => ['token' => 'tok']]),
            self::SANDBOX.'/v1beta/domains/check' => Http::response(['data' => ['results' => [['status' => 'free']]]]),
            self::SANDBOX.'/v1beta/customers' => Http::response(['data' => ['handle' => 'NP1-EU']]),
            self::SANDBOX.'/v1beta/domains' => Http::response(['data' => [
                'id' => 555, 'status' => 'ACT', 'expiration_date' => '2027-09-08 00:00:00', 'name_servers' => [],
            ]]),
        ]);
        $domain = $this->pendingDomain();

        $log = app(DomainRegistrationService::class)->register($domain, 1);

        $this->assertSame(DomainRegistrarLog::STATUS_OK, $log->status);
        $domain->refresh();
        $this->assertSame(DomainStatus::Active, $domain->status);
        $this->assertSame('2027-09-08', $domain->expires_at->toDateString());
        $this->assertSame('555', $domain->registrar_domain_id);
        $this->assertSame(today()->toDateString(), $domain->registered_at->toDateString());
        // the ensured handle persisted onto the contact — never re-created
        $this->assertSame('NP1-EU', $domain->contacts()->sole()->registrar_contact_handle);

        Http::assertSent(function ($req) {
            if (! str_ends_with($req->url(), '/v1beta/domains') || $req->method() !== 'POST') {
                return false;
            }

            return $req['period'] === 1
                && $req['owner_handle'] === 'NP1-EU'
                && $req['admin_handle'] === 'NP1-EU' // missing types fall back to registrant
                && $req['autorenew'] === 'off'       // the billing clock is OURS
                && $req['name_servers'] === [['name' => 'ns1.myip.gr'], ['name' => 'ns2.myip.gr']];
        });
    }

    public function test_an_existing_handle_is_reused_never_recreated(): void
    {
        Http::fake([
            self::SANDBOX.'/v1beta/auth/login' => Http::response(['data' => ['token' => 'tok']]),
            self::SANDBOX.'/v1beta/domains/check' => Http::response(['data' => ['results' => [['status' => 'free']]]]),
            self::SANDBOX.'/v1beta/domains' => Http::response(['data' => [
                'id' => 556, 'status' => 'ACT', 'expiration_date' => '2027-09-08 00:00:00',
            ]]),
        ]);
        $domain = $this->pendingDomain();
        $domain->contacts()->update(['registrar_contact_handle' => 'OLD1-EU']);

        app(DomainRegistrationService::class)->register($domain, 1);

        Http::assertNotSent(fn ($req) => str_ends_with($req->url(), '/v1beta/customers'));
        Http::assertSent(fn ($req) => str_ends_with($req->url(), '/v1beta/domains')
            && $req->method() === 'POST' && $req['owner_handle'] === 'OLD1-EU');
    }

    public function test_a_taken_name_that_is_ours_is_adopted_never_paid_twice(): void
    {
        // The retry-after-timeout war story, register flavor: the charge went
        // through but ekdosi never saw the response. The retry finds the name
        // taken AND in our account → adopt, zero register calls.
        Http::fake([
            self::SANDBOX.'/v1beta/auth/login' => Http::response(['data' => ['token' => 'tok']]),
            self::SANDBOX.'/v1beta/domains/check' => Http::response(['data' => ['results' => [['status' => 'active']]]]),
            self::SANDBOX.'/v1beta/domains?full_name=fresh.eu' => Http::response(['data' => ['results' => [[
                'id' => 555, 'status' => 'ACT', 'expiration_date' => '2027-09-08 00:00:00', 'name_servers' => [],
            ]]]]),
        ]);
        $domain = $this->pendingDomain();

        $log = app(DomainRegistrationService::class)->register($domain, 1);

        $this->assertSame(DomainRegistrarLog::STATUS_ADOPTED, $log->status);
        Http::assertNotSent(fn ($req) => str_ends_with($req->url(), '/v1beta/domains') && $req->method() === 'POST');
        $domain->refresh();
        $this->assertSame(DomainStatus::Active, $domain->status);
        $this->assertSame('2027-09-08', $domain->expires_at->toDateString());
    }

    public function test_a_name_taken_by_a_third_party_refuses_loudly(): void
    {
        Http::fake([
            self::SANDBOX.'/v1beta/auth/login' => Http::response(['data' => ['token' => 'tok']]),
            self::SANDBOX.'/v1beta/domains/check' => Http::response(['data' => ['results' => [['status' => 'active']]]]),
            self::SANDBOX.'/v1beta/domains?full_name=fresh.eu' => Http::response(['data' => ['results' => []]]),
        ]);
        $domain = $this->pendingDomain();

        try {
            app(DomainRegistrationService::class)->register($domain, 1);
            $this->fail('a third-party name must refuse');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('κατειλημμένο', $e->getMessage());
        }
        Http::assertNotSent(fn ($req) => str_ends_with($req->url(), '/v1beta/domains') && $req->method() === 'POST');
        $this->assertSame(DomainStatus::PendingRegister, $domain->refresh()->status, 'still pending — operator decides next move');
    }

    public function test_an_availability_transport_failure_aborts_before_any_charge(): void
    {
        Http::fake([
            self::SANDBOX.'/v1beta/auth/login' => Http::response(['data' => ['token' => 'tok']]),
            self::SANDBOX.'/v1beta/domains/check' => Http::response(['desc' => 'down'], 500),
        ]);
        $domain = $this->pendingDomain();

        try {
            app(DomainRegistrationService::class)->register($domain, 1);
            $this->fail('never register blind');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('προ-έλεγχος', $e->getMessage());
        }
        Http::assertNotSent(fn ($req) => str_ends_with($req->url(), '/v1beta/domains') && $req->method() === 'POST');
        $this->assertSame(DomainRegistrarLog::STATUS_FAILED, DomainRegistrarLog::sole()->status);
    }

    public function test_refusals_wrong_status_no_contact_few_nameservers_manual(): void
    {
        $service = app(DomainRegistrationService::class);
        Http::fake();

        $active = $this->pendingDomain(['sld' => 'a1', 'fqdn' => 'a1.eu', 'status' => 'active']);
        try {
            $service->register($active, 1);
            $this->fail('only pending registers');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('Εκκρεμεί καταχώρηση', $e->getMessage());
        }

        $noContact = $this->pendingDomain(['sld' => 'a2', 'fqdn' => 'a2.eu'], withContact: false);
        try {
            $service->register($noContact, 1);
            $this->fail('registrant contact required');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('registrant', $e->getMessage());
        }

        $oneNs = $this->pendingDomain(['sld' => 'a3', 'fqdn' => 'a3.eu'], nameservers: 1);
        try {
            $service->register($oneNs, 1);
            $this->fail('two nameservers required');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('nameservers', $e->getMessage());
        }

        $manual = DomainRegistrarConnection::create([
            'company_id' => $this->company->id, 'registrar' => 'manual',
            'is_active' => true, 'mode' => 'production', 'config' => [],
        ]);
        $manualDomain = $this->pendingDomain(['sld' => 'a4', 'fqdn' => 'a4.eu', 'registrar_connection_id' => $manual->id]);
        try {
            $service->register($manualDomain, 1);
            $this->fail('manual refuses');
        } catch (DomainRegistrarNotConfigured) {
        }

        Http::assertNothingSent();
        $this->assertSame(4, DomainRegistrarLog::where('status', DomainRegistrarLog::STATUS_FAILED)->count(), 'every refusal logs');
    }

    public function test_a_concurrent_registration_is_refused_by_the_lock(): void
    {
        $domain = $this->pendingDomain();
        $lock = Cache::lock('domains:register:'.$domain->id, 60);
        $this->assertTrue($lock->get());

        try {
            Http::fake();
            try {
                app(DomainRegistrationService::class)->register($domain, 1);
                $this->fail('concurrent registration must refuse');
            } catch (DomainRenewalInProgress $e) {
                $this->assertStringContainsString('σε εξέλιξη', $e->getMessage());
            }
            Http::assertNothingSent();
        } finally {
            $lock->release();
        }
    }
}
