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
use App\Services\Domains\DomainRenewalInProgress;
use App\Services\Domains\DomainSyncService;
use App\Services\Domains\DomainTransferService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Tests\TestCase;

/**
 * Πυλώνας A / A3c — the transfer slice (§6.3): inbound transfers are async
 * (start → PendingTransfer, the nightly sync completes or ⚠-flags), adopt-on-
 * retry never pays twice, the auth code NEVER lands in any log, and the EPP
 * code retrieval (transfer-out aid) is audit-logged WITHOUT the code. All
 * against mocked HTTP.
 */
class DomainTransferServiceTest extends TestCase
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

    private function pendingTransferDomain(array $extra = [], bool $withContact = true): Domain
    {
        $domain = Domain::create(array_merge([
            'company_id' => $this->company->id, 'domain_tld_id' => $this->tld->id,
            'sld' => 'moving', 'tld' => 'eu', 'fqdn' => 'moving.eu',
            'status' => DomainStatus::PendingTransfer,
        ], $extra));
        if ($withContact) {
            DomainContact::create([
                'company_id' => $this->company->id, 'domain_id' => $domain->id,
                'type' => 'registrant', 'name' => 'Νίκος Π.', 'email' => 'n@myip.gr',
                'registrar_contact_handle' => 'NP1-EU',
            ]);
        }

        return $domain;
    }

    public function test_transfer_in_starts_stays_pending_and_never_logs_the_auth_code(): void
    {
        Http::fake([
            self::SANDBOX.'/v1beta/auth/login' => Http::response(['data' => ['token' => 'tok']]),
            self::SANDBOX.'/v1beta/domains/transfer' => Http::response(['data' => [
                'id' => 700, 'status' => 'REQ',
            ]]),
        ]);
        $domain = $this->pendingTransferDomain(); // no NS rows: keep delegation

        $log = app(DomainTransferService::class)->transferIn($domain, 'SECRET-EPP-123');

        $this->assertSame(DomainRegistrarLog::STATUS_OK, $log->status);
        $domain->refresh();
        $this->assertSame(DomainStatus::PendingTransfer, $domain->status, 'async — the sync completes it');
        $this->assertSame('700', $domain->registrar_domain_id);

        Http::assertSent(function ($req) {
            if (! str_ends_with($req->url(), '/v1beta/domains/transfer')) {
                return false;
            }

            return $req['auth_code'] === 'SECRET-EPP-123'
                && $req['period'] === 1
                && $req['autorenew'] === 'off'
                && $req['owner_handle'] === 'NP1-EU'
                && ! array_key_exists('name_servers', $req->data()); // delegation kept
        });
        // The auth code is a bearer credential for the name — NEVER in a log.
        $this->assertStringNotContainsString('SECRET-EPP-123', json_encode(DomainRegistrarLog::all()->toArray()));
    }

    public function test_a_retry_adopts_the_inflight_transfer_never_pays_twice(): void
    {
        Http::fake([
            self::SANDBOX.'/v1beta/auth/login' => Http::response(['data' => ['token' => 'tok']]),
            self::SANDBOX.'/v1beta/domains?full_name=moving.eu' => Http::response(['data' => ['results' => [[
                'id' => 700, 'status' => 'REQ',
            ]]]]),
        ]);
        $domain = $this->pendingTransferDomain();
        DomainRegistrarLog::create([ // the timed-out first attempt
            'company_id' => $this->company->id, 'domain_id' => $domain->id,
            'registrar_connection_id' => $this->connection->id,
            'action' => 'transfer_in', 'status' => DomainRegistrarLog::STATUS_FAILED,
            'request' => ['fqdn' => 'moving.eu'], 'error' => 'cURL timeout',
        ]);

        $log = app(DomainTransferService::class)->transferIn($domain, 'SECRET-EPP-123');

        $this->assertSame(DomainRegistrarLog::STATUS_ADOPTED, $log->status);
        Http::assertNotSent(fn ($req) => str_ends_with($req->url(), '/v1beta/domains/transfer'));
        $this->assertSame('700', $domain->refresh()->registrar_domain_id);
    }

    public function test_a_dead_prior_request_restarts_the_transfer_fresh(): void
    {
        // The earlier request FAI-ed at the registry — the probe sees the
        // tombstone and a fresh transfer starts (with the new auth code).
        Http::fake([
            self::SANDBOX.'/v1beta/auth/login' => Http::response(['data' => ['token' => 'tok']]),
            self::SANDBOX.'/v1beta/domains/700' => Http::response(['data' => ['id' => 700, 'status' => 'FAI']]),
            self::SANDBOX.'/v1beta/domains/transfer' => Http::response(['data' => ['id' => 701, 'status' => 'REQ']]),
        ]);
        $domain = $this->pendingTransferDomain(['registrar_domain_id' => '700']);

        $log = app(DomainTransferService::class)->transferIn($domain, 'NEW-EPP-456');

        $this->assertSame(DomainRegistrarLog::STATUS_OK, $log->status);
        $this->assertSame('701', $domain->refresh()->registrar_domain_id, 'the NEW request id');
    }

    public function test_the_nightly_sync_flags_a_failed_pending_transfer(): void
    {
        // §6.3 'failed' leg: the record turned FAI — sync_error ⚠ lands on
        // the row, the status stays pending for the operator's decision.
        Http::fake([
            self::SANDBOX.'/v1beta/auth/login' => Http::response(['data' => ['token' => 'tok']]),
            self::SANDBOX.'/v1beta/domains/700' => Http::response(['data' => ['id' => 700, 'status' => 'FAI']]),
        ]);
        $domain = $this->pendingTransferDomain(['registrar_domain_id' => '700']);

        app(DomainSyncService::class)->sync($domain);

        $domain->refresh();
        $this->assertSame(DomainStatus::PendingTransfer, $domain->status);
        $this->assertStringContainsString('απέτυχε στον registrar', $domain->sync_error);
        $this->assertStringContainsString('FAI', $domain->sync_error);
    }

    public function test_refusals_wrong_status_no_contact_manual_and_lock(): void
    {
        $service = app(DomainTransferService::class);
        Http::fake();

        $active = $this->pendingTransferDomain(['sld' => 'a1', 'fqdn' => 'a1.eu', 'status' => 'active']);
        try {
            $service->transferIn($active, 'X');
            $this->fail('only pending_transfer transfers');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('Εκκρεμεί μεταφορά', $e->getMessage());
        }

        $noContact = $this->pendingTransferDomain(['sld' => 'a2', 'fqdn' => 'a2.eu'], withContact: false);
        try {
            $service->transferIn($noContact, 'X');
            $this->fail('registrant required');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('registrant', $e->getMessage());
        }

        $manual = DomainRegistrarConnection::create([
            'company_id' => $this->company->id, 'registrar' => 'manual',
            'is_active' => true, 'mode' => 'production', 'config' => [],
        ]);
        $manualDomain = $this->pendingTransferDomain(['sld' => 'a3', 'fqdn' => 'a3.eu', 'registrar_connection_id' => $manual->id]);
        try {
            $service->transferIn($manualDomain, 'X');
            $this->fail('manual refuses');
        } catch (DomainRegistrarNotConfigured) {
        }

        $locked = $this->pendingTransferDomain(['sld' => 'a4', 'fqdn' => 'a4.eu']);
        $lock = Cache::lock('domains:transfer:'.$locked->id, 60);
        $this->assertTrue($lock->get());
        try {
            $service->transferIn($locked, 'X');
            $this->fail('lock refuses');
        } catch (DomainRenewalInProgress) {
        } finally {
            $lock->release();
        }

        Http::assertNothingSent();
        $this->assertSame(4, DomainRegistrarLog::where('status', DomainRegistrarLog::STATUS_FAILED)->count());
    }

    public function test_epp_code_is_returned_and_audited_without_the_code(): void
    {
        Http::fake([
            self::SANDBOX.'/v1beta/auth/login' => Http::response(['data' => ['token' => 'tok']]),
            self::SANDBOX.'/v1beta/domains/800/authcode' => Http::response(['data' => ['auth_code' => 'OUT-CODE-999']]),
        ]);
        $domain = $this->pendingTransferDomain(['sld' => 'own', 'fqdn' => 'own.eu', 'status' => 'active', 'registrar_domain_id' => '800']);

        $code = app(DomainTransferService::class)->eppCode($domain);

        $this->assertSame('OUT-CODE-999', $code);
        $log = DomainRegistrarLog::where('action', 'epp_code')->sole();
        $this->assertSame(DomainRegistrarLog::STATUS_OK, $log->status);
        $this->assertTrue($log->response['retrieved']);
        $this->assertStringNotContainsString('OUT-CODE-999', json_encode($log->toArray()), 'the code must never persist');
    }

    public function test_epp_code_failures_are_audited_too(): void
    {
        Http::fake([
            self::SANDBOX.'/v1beta/auth/login' => Http::response(['data' => ['token' => 'tok']]),
            self::SANDBOX.'/v1beta/domains/800/authcode' => Http::response(['desc' => 'nope'], 500),
        ]);
        $domain = $this->pendingTransferDomain(['sld' => 'own2', 'fqdn' => 'own2.eu', 'status' => 'active', 'registrar_domain_id' => '800']);

        try {
            app(DomainTransferService::class)->eppCode($domain);
            $this->fail('the failure surfaces');
        } catch (RuntimeException) {
        }
        $this->assertSame(DomainRegistrarLog::STATUS_FAILED, DomainRegistrarLog::where('action', 'epp_code')->sole()->status);
    }
}
