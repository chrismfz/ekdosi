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
        $this->assertSame(today()->toDateString(), $domain->registered_at->toDateString(), 'best-known date stamped on adopt too');
    }

    public function test_an_inflight_async_registration_never_posts_again_even_if_check_says_free(): void
    {
        // r1 finding 1: async registries (REQ) may still answer 'free' while
        // processing — a row that already reached the registrar (id present)
        // must go straight to adopt, availability NEVER consulted.
        Http::fake([
            self::SANDBOX.'/v1beta/auth/login' => Http::response(['data' => ['token' => 'tok']]),
            self::SANDBOX.'/v1beta/domains/9' => Http::response(['data' => [
                'id' => 9, 'status' => 'REQ', 'expiration_date' => '2027-09-08 00:00:00',
            ]]),
        ]);
        $domain = $this->pendingDomain(['registrar_domain_id' => '9']);

        $log = app(DomainRegistrationService::class)->register($domain, 1);

        $this->assertSame(DomainRegistrarLog::STATUS_ADOPTED, $log->status);
        Http::assertNotSent(fn ($req) => str_ends_with($req->url(), '/v1beta/domains/check'));
        Http::assertNotSent(fn ($req) => str_ends_with($req->url(), '/v1beta/domains') && $req->method() === 'POST');
        $this->assertSame(DomainStatus::PendingRegister, $domain->refresh()->status, 'REQ keeps pending until the registry answers');
    }

    public function test_a_timed_out_register_that_charged_is_adopted_on_retry_not_paid_twice(): void
    {
        // r2 finding 1 — THE war story, register flavor: the first POST timed
        // out AFTER charging (only a 'failed' log, NO id), and the async
        // registry still answers 'free'. The retry must PROBE the account
        // first and adopt — never trust availability into a second POST.
        Http::fake([
            self::SANDBOX.'/v1beta/auth/login' => Http::response(['data' => ['token' => 'tok']]),
            self::SANDBOX.'/v1beta/domains?full_name=fresh.eu' => Http::response(['data' => ['results' => [[
                'id' => 555, 'status' => 'ACT', 'expiration_date' => '2027-09-08 00:00:00',
            ]]]]),
        ]);
        $domain = $this->pendingDomain();
        DomainRegistrarLog::create([
            'company_id' => $this->company->id, 'domain_id' => $domain->id,
            'registrar_connection_id' => $this->connection->id,
            'action' => 'register', 'status' => DomainRegistrarLog::STATUS_FAILED,
            'request' => ['fqdn' => 'fresh.eu', 'years' => 1], 'error' => 'cURL timeout',
        ]);

        $log = app(DomainRegistrationService::class)->register($domain, 1);

        $this->assertSame(DomainRegistrarLog::STATUS_ADOPTED, $log->status);
        Http::assertNotSent(fn ($req) => str_ends_with($req->url(), '/v1beta/domains') && $req->method() === 'POST');
        Http::assertNotSent(fn ($req) => str_ends_with($req->url(), '/v1beta/domains/check'));
        $this->assertSame('555', $domain->refresh()->registrar_domain_id);
    }

    public function test_a_probe_that_finds_nothing_falls_through_to_a_real_register(): void
    {
        // ...and when the timed-out POST genuinely never landed, the probe
        // says not-ours and the normal availability+register path runs ONCE.
        Http::fake([
            self::SANDBOX.'/v1beta/auth/login' => Http::response(['data' => ['token' => 'tok']]),
            self::SANDBOX.'/v1beta/domains?full_name=fresh.eu' => Http::response(['data' => ['results' => []]]),
            self::SANDBOX.'/v1beta/domains/check' => Http::response(['data' => ['results' => [['status' => 'free']]]]),
            self::SANDBOX.'/v1beta/customers' => Http::response(['data' => ['handle' => 'NP1-EU']]),
            self::SANDBOX.'/v1beta/domains' => Http::response(['data' => [
                'id' => 557, 'status' => 'ACT', 'expiration_date' => '2027-09-08 00:00:00',
            ]]),
        ]);
        $domain = $this->pendingDomain();
        DomainRegistrarLog::create([
            'company_id' => $this->company->id, 'domain_id' => $domain->id,
            'registrar_connection_id' => $this->connection->id,
            'action' => 'register', 'status' => DomainRegistrarLog::STATUS_FAILED,
            'request' => ['fqdn' => 'fresh.eu', 'years' => 1], 'error' => 'cURL timeout',
        ]);

        $log = app(DomainRegistrationService::class)->register($domain, 1);

        $this->assertSame(DomainRegistrarLog::STATUS_OK, $log->status);
        $this->assertSame('557', $domain->refresh()->registrar_domain_id);
    }

    public function test_a_rejected_inflight_registration_clears_the_stale_id_never_says_third_party(): void
    {
        // r2 finding 2: REQ later rejected by the registry, OP dropped the
        // object — the honest message + a cleared id (unwedged), never the
        // «κατειλημμένο από τρίτο» fiction.
        Http::fake([
            self::SANDBOX.'/v1beta/auth/login' => Http::response(['data' => ['token' => 'tok']]),
            self::SANDBOX.'/v1beta/domains/9' => Http::response(['desc' => 'not found'], 404),
            self::SANDBOX.'/v1beta/domains?full_name=fresh.eu' => Http::response(['data' => ['results' => []]]),
        ]);
        $domain = $this->pendingDomain(['registrar_domain_id' => '9']);

        try {
            app(DomainRegistrationService::class)->register($domain, 1);
            $this->fail('the rejected in-flight must fail honestly');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('δεν βρίσκεται (πλέον) στον λογαριασμό', $e->getMessage());
            $this->assertStringNotContainsString('κατειλημμένο', $e->getMessage());
        }
        $domain->refresh();
        $this->assertNull($domain->registrar_domain_id, 'stale id cleared — the next attempt takes the normal path');
        $this->assertSame('9', $domain->module_meta['previous_registrar_domain_id']);
    }

    public function test_a_panel_registered_premium_name_is_still_adoptable(): void
    {
        // r2 finding 4: adoption charges nothing — the premium guard protects
        // only the CHARGE, so a taken premium name in OUR account adopts.
        Http::fake([
            self::SANDBOX.'/v1beta/auth/login' => Http::response(['data' => ['token' => 'tok']]),
            self::SANDBOX.'/v1beta/domains/check' => Http::response(['data' => ['results' => [[
                'status' => 'active', 'premium' => ['price' => ['create' => 950]],
            ]]]]),
            self::SANDBOX.'/v1beta/domains?full_name=fresh.eu' => Http::response(['data' => ['results' => [[
                'id' => 555, 'status' => 'ACT', 'expiration_date' => '2027-09-08 00:00:00',
            ]]]]),
        ]);
        $domain = $this->pendingDomain();

        $log = app(DomainRegistrationService::class)->register($domain, 1);

        $this->assertSame(DomainRegistrarLog::STATUS_ADOPTED, $log->status);
    }

    public function test_a_lingering_deleted_record_is_not_ours_the_rebuy_registers_fresh(): void
    {
        // r3 P1: a lapsed name our account once held lingers as DEL at OP.
        // The customer re-buys it: the probe must treat the dead record as
        // NOT-ours and register fresh — adopting it would wedge the row
        // Deleted forever while the name is genuinely free.
        Http::fake([
            self::SANDBOX.'/v1beta/auth/login' => Http::response(['data' => ['token' => 'tok']]),
            self::SANDBOX.'/v1beta/domains?full_name=fresh.eu' => Http::response(['data' => ['results' => [[
                'id' => 400, 'status' => 'DEL', 'expiration_date' => '2024-01-01 00:00:00',
            ]]]]),
            self::SANDBOX.'/v1beta/domains/check' => Http::response(['data' => ['results' => [['status' => 'free']]]]),
            self::SANDBOX.'/v1beta/customers' => Http::response(['data' => ['handle' => 'NP1-EU']]),
            self::SANDBOX.'/v1beta/domains' => Http::response(['data' => [
                'id' => 558, 'status' => 'ACT', 'expiration_date' => '2027-09-08 00:00:00',
            ]]),
        ]);
        $domain = $this->pendingDomain();
        DomainRegistrarLog::create([ // e.g. a refused first click → probe path active
            'company_id' => $this->company->id, 'domain_id' => $domain->id,
            'registrar_connection_id' => $this->connection->id,
            'action' => 'register', 'status' => DomainRegistrarLog::STATUS_FAILED,
            'request' => ['fqdn' => 'fresh.eu'], 'error' => 'refused',
        ]);

        $log = app(DomainRegistrationService::class)->register($domain, 1);

        $this->assertSame(DomainRegistrarLog::STATUS_OK, $log->status, 'a REAL fresh register ran');
        $domain->refresh();
        $this->assertSame(DomainStatus::Active, $domain->status);
        $this->assertSame('558', $domain->registrar_domain_id, 'the NEW id, never the dead record\'s');
    }

    public function test_an_inflight_pointing_at_a_deleted_record_clears_and_fails_honestly(): void
    {
        Http::fake([
            self::SANDBOX.'/v1beta/auth/login' => Http::response(['data' => ['token' => 'tok']]),
            self::SANDBOX.'/v1beta/domains/400' => Http::response(['data' => [
                'id' => 400, 'status' => 'DEL', 'expiration_date' => '2024-01-01 00:00:00',
            ]]),
        ]);
        $domain = $this->pendingDomain(['registrar_domain_id' => '400']);

        try {
            app(DomainRegistrationService::class)->register($domain, 1);
            $this->fail('a dead in-flight record must fail honestly');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('δεν βρίσκεται (πλέον)', $e->getMessage());
        }
        $this->assertNull($domain->refresh()->registrar_domain_id, 'unwedged for the next deliberate attempt');
        $this->assertSame(DomainStatus::PendingRegister, $domain->status, 'never adopted as Deleted');
    }

    public function test_a_failed_account_read_is_never_dressed_up_as_taken_by_third_party(): void
    {
        Http::fake([
            self::SANDBOX.'/v1beta/auth/login' => Http::response(['data' => ['token' => 'tok']]),
            self::SANDBOX.'/v1beta/domains/check' => Http::response(['data' => ['results' => [['status' => 'active']]]]),
            self::SANDBOX.'/v1beta/domains?full_name=fresh.eu' => Http::response(['desc' => 'boom'], 502),
        ]);
        $domain = $this->pendingDomain();

        try {
            app(DomainRegistrationService::class)->register($domain, 1);
            $this->fail('a failed read must abort, not lie');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('έλεγχος του λογαριασμού απέτυχε', $e->getMessage());
            $this->assertStringNotContainsString('κατειλημμένο', $e->getMessage());
        }
    }

    public function test_a_premium_name_refuses_instead_of_charging_an_unknown_price(): void
    {
        Http::fake([
            self::SANDBOX.'/v1beta/auth/login' => Http::response(['data' => ['token' => 'tok']]),
            self::SANDBOX.'/v1beta/domains/check' => Http::response(['data' => ['results' => [[
                'status' => 'free', 'premium' => ['price' => ['create' => 950]],
            ]]]]),
        ]);
        $domain = $this->pendingDomain();

        try {
            app(DomainRegistrationService::class)->register($domain, 1);
            $this->fail('premium must refuse');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('PREMIUM', $e->getMessage());
        }
        Http::assertNotSent(fn ($req) => str_ends_with($req->url(), '/v1beta/domains') && $req->method() === 'POST');
    }

    public function test_a_name_claimed_by_another_tenant_on_the_shared_account_refuses(): void
    {
        // Shared reseller creds across the owner's companies: another tenant's
        // registered name must never be adopted here (cross-tenant leak).
        $other = Company::create([
            'name' => 'Other', 'slug' => 'o-'.uniqid(), 'country_code' => 'GR',
            'einvoice_provider' => 'gr-mydata', 'mydata_mode' => 'off',
            'enable_domain_management' => true,
        ]);
        $otherTld = DomainTld::create(['company_id' => $other->id, 'tld' => 'eu', 'is_active' => true]);
        Domain::create([
            'company_id' => $other->id, 'domain_tld_id' => $otherTld->id,
            'sld' => 'fresh', 'tld' => 'eu', 'fqdn' => 'fresh.eu',
            'status' => 'active', 'registrar_domain_id' => '555',
        ]);
        $domain = $this->pendingDomain();

        Http::fake();
        try {
            app(DomainRegistrationService::class)->register($domain, 1);
            $this->fail('cross-tenant claim must refuse');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('ΑΛΛΗ εταιρεία', $e->getMessage());
        }
        Http::assertNothingSent();
    }

    public function test_handles_created_before_a_failed_register_survive_for_the_retry(): void
    {
        // r1 finding 3: the handle persists AS ensured — a register failure
        // must not orphan the OP customer and re-create it on retry.
        Http::fake([
            self::SANDBOX.'/v1beta/auth/login' => Http::response(['data' => ['token' => 'tok']]),
            self::SANDBOX.'/v1beta/domains/check' => Http::response(['data' => ['results' => [['status' => 'free']]]]),
            self::SANDBOX.'/v1beta/customers' => Http::response(['data' => ['handle' => 'NP1-EU']]),
            self::SANDBOX.'/v1beta/domains' => Http::response(['desc' => 'not enough balance'], 400),
        ]);
        $domain = $this->pendingDomain();

        try {
            app(DomainRegistrationService::class)->register($domain, 1);
            $this->fail('the register failed');
        } catch (RuntimeException) {
        }

        $this->assertSame('NP1-EU', $domain->contacts()->sole()->registrar_contact_handle, 'the handle survived the failure');
        // phone was split correctly for the OP customer (ICANN WHOIS data)
        Http::assertSent(fn ($req) => str_ends_with($req->url(), '/v1beta/customers')
            && $req['phone']['country_code'] === '+30'
            && $req['phone']['subscriber_number'] === '2101234567');
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
