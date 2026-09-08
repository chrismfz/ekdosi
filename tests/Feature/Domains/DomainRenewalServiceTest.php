<?php

namespace Tests\Feature\Domains;

use App\Enums\DomainStatus;
use App\Models\Company;
use App\Models\Customer;
use App\Models\Domain;
use App\Models\DomainRegistrarConnection;
use App\Models\DomainRegistrarLog;
use App\Models\DomainTld;
use App\Models\Invoice;
use App\Models\InvoiceType;
use App\Models\ServiceContract;
use App\Services\Domains\DomainRegistrarNotConfigured;
use App\Services\Domains\DomainRenewalService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Tests\TestCase;

/**
 * Πυλώνας A / A3a — the FIRST write: registrar renew, gated by the §6.6
 * ΔΕΣΜΕΥΤΙΚΟ adopt-on-already-renewed guard (the WHMCS double-renew/relid war
 * story). Sync-first before every renew; already-covered periods ADOPT with
 * no registrar call; every attempt lands in domain_registrar_logs. All
 * against mocked HTTP — no live registrar calls in CI, ever.
 */
class DomainRenewalServiceTest extends TestCase
{
    use RefreshDatabase;

    private const SANDBOX = 'http://api.sandbox.openprovider.nl:8480';

    private Company $company;

    private DomainTld $tld;

    private DomainRegistrarConnection $connection;

    private Customer $customer;

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
            'company_id' => $this->company->id, 'tld' => 'gr',
            'registrar_connection_id' => $this->connection->id, 'is_active' => true, 'min_years' => 1,
        ]);
        $this->customer = Customer::create(['company_id' => $this->company->id, 'name' => 'Πελάτης']);
    }

    /** @return array{0: Domain, 1: ServiceContract} an assigned, annually-billed domain */
    private function assignedDomain(string $expiresAt = '2026-01-01'): array
    {
        $contract = ServiceContract::create([
            'company_id' => $this->company->id, 'customer_id' => $this->customer->id,
            'description' => 'Ανανέωση domain example.gr', 'billing_cycle' => 'annual',
            'quantity' => 1, 'amount' => 15, 'vat_percent' => 24, 'status' => 'active',
            'start_date' => '2020-01-01', 'next_due_date' => $expiresAt,
            'provisioning_module' => 'none', 'domain' => 'example.gr',
        ]);
        $domain = Domain::create([
            'company_id' => $this->company->id, 'domain_tld_id' => $this->tld->id,
            'customer_id' => $this->customer->id, 'service_contract_id' => $contract->id,
            'sld' => 'example', 'tld' => 'gr', 'fqdn' => 'example.gr',
            'expires_at' => $expiresAt, 'status' => 'active', 'auto_renew' => true,
            'registrar_domain_id' => '77',
        ]);

        return [$domain, $contract];
    }

    /** @return array<string, mixed> */
    private function opDomain(string $expiry): array
    {
        return ['id' => 77, 'status' => 'ACT', 'expiration_date' => $expiry.' 00:00:00', 'name_servers' => []];
    }

    public function test_renew_fires_when_the_period_is_not_covered_and_applies_fresh_truth(): void
    {
        Http::fake([
            self::SANDBOX.'/v1beta/auth/login' => Http::response(['data' => ['token' => 'tok']]),
            // 3 GETs: the §6.6 pre-check sync, renew()'s id resolve, the post-renew re-fetch
            self::SANDBOX.'/v1beta/domains/77' => Http::sequence()
                ->push(['data' => $this->opDomain('2026-01-01')])
                ->push(['data' => $this->opDomain('2026-01-01')])
                ->push(['data' => $this->opDomain('2027-01-01')]),
            self::SANDBOX.'/v1beta/domains/77/renew' => Http::response(['code' => 0]),
        ]);
        [$domain] = $this->assignedDomain();

        $log = app(DomainRenewalService::class)->renew($domain, 1);

        $this->assertSame(DomainRegistrarLog::STATUS_OK, $log->status);
        $this->assertSame('2027-01-01', $domain->refresh()->expires_at->toDateString());
        $this->assertSame('2027-01-01', $log->request['target_expiry']);
        Http::assertSent(fn ($req) => str_contains($req->url(), '/domains/77/renew') && $req['period'] === 1);
    }

    public function test_the_war_story_an_already_renewed_domain_is_adopted_never_double_renewed(): void
    {
        // «Ανανέωσέ το και σε πληρώνω τέλος του μήνα»: the operator renewed via
        // the button (or the registrar panel); when the invoice is later
        // issued, the pre-check sync sees the covered period and ADOPTS.
        Http::fake([
            self::SANDBOX.'/v1beta/auth/login' => Http::response(['data' => ['token' => 'tok']]),
            self::SANDBOX.'/v1beta/domains/77' => Http::response(['data' => $this->opDomain('2027-01-01')]),
        ]);
        [$domain, $contract] = $this->assignedDomain('2026-01-01'); // SC cursor = the period start
        $domain->forceFill(['expires_at' => '2027-01-01'])->save(); // the button already moved it

        $type = InvoiceType::create(['company_id' => $this->company->id, 'code' => 'TDA', 'name' => 'ΤΔΑ', 'invcount' => 0, 'mydata_type' => '2.1']);
        $invoice = Invoice::create([
            'company_id' => $this->company->id, 'invoice_type_id' => $type->id,
            'customer_id' => $this->customer->id, 'service_contract_id' => $contract->id,
            'code' => 1, 'invcode' => 'TDA1', 'issued_at' => now(), 'local_status' => 'draft',
        ]);

        $log = app(DomainRenewalService::class)->renewForInvoice($invoice);

        // Baseline = the SC cursor (2026) → target 2027 → registrar already
        // reports 2027 → adopted, NO renew POST ever left the building.
        $this->assertSame(DomainRegistrarLog::STATUS_ADOPTED, $log->status);
        Http::assertNotSent(fn ($req) => str_contains($req->url(), '/renew'));
        $this->assertSame('2027-01-01', $domain->refresh()->expires_at->toDateString());
        $this->assertSame($invoice->id, $log->invoice_id);
    }

    public function test_a_failed_precheck_sync_aborts_without_writing_to_the_registrar(): void
    {
        Http::fake([
            self::SANDBOX.'/v1beta/auth/login' => Http::response(['data' => ['token' => 'tok']]),
            self::SANDBOX.'/v1beta/domains/77' => Http::response(['desc' => 'boom'], 500),
        ]);
        [$domain] = $this->assignedDomain();

        try {
            app(DomainRenewalService::class)->renew($domain, 1);
            $this->fail('a failed pre-check must abort the renewal');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('προ-έλεγχος', $e->getMessage());
        }

        Http::assertNotSent(fn ($req) => str_contains($req->url(), '/renew'));
        $log = DomainRegistrarLog::where('domain_id', $domain->id)->sole();
        $this->assertSame(DomainRegistrarLog::STATUS_FAILED, $log->status);
    }

    public function test_refusals_dead_set_manual_and_missing_expiry(): void
    {
        $service = app(DomainRenewalService::class);

        [$trashed] = $this->assignedDomain();
        $trashed->delete();
        try {
            $service->renew($trashed, 1);
            $this->fail('trashed must refuse');
        } catch (RuntimeException) {
        }

        $redemption = Domain::create([
            'company_id' => $this->company->id, 'domain_tld_id' => $this->tld->id,
            'sld' => 'red', 'tld' => 'gr', 'fqdn' => 'red.gr',
            'expires_at' => '2026-01-01', 'status' => DomainStatus::Redemption,
        ]);
        try {
            $service->renew($redemption, 1);
            $this->fail('redemption needs restore, not renew');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('Redemption', $e->getMessage());
        }

        $manual = DomainRegistrarConnection::create([
            'company_id' => $this->company->id, 'registrar' => 'manual',
            'is_active' => true, 'mode' => 'production', 'config' => [],
        ]);
        $manualDomain = Domain::create([
            'company_id' => $this->company->id, 'domain_tld_id' => $this->tld->id,
            'registrar_connection_id' => $manual->id,
            'sld' => 'man', 'tld' => 'gr', 'fqdn' => 'man.gr',
            'expires_at' => '2026-01-01', 'status' => 'active',
        ]);
        try {
            $service->renew($manualDomain, 1);
            $this->fail('manual must refuse loudly');
        } catch (DomainRegistrarNotConfigured) {
        }

        $noExpiry = Domain::create([
            'company_id' => $this->company->id, 'domain_tld_id' => $this->tld->id,
            'sld' => 'noexp', 'tld' => 'gr', 'fqdn' => 'noexp.gr', 'status' => 'active',
        ]);
        try {
            $service->renew($noExpiry, 1);
            $this->fail('unknown expiry must refuse');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('λήξη', $e->getMessage());
        }

        $this->assertSame(0, DomainRegistrarLog::count(), 'refusals happen before any registrar traffic — nothing to log');
    }

    public function test_renew_for_invoice_skips_non_domain_contracts_and_is_idempotent(): void
    {
        [$domain, $contract] = $this->assignedDomain();
        $type = InvoiceType::create(['company_id' => $this->company->id, 'code' => 'TDA', 'name' => 'ΤΔΑ', 'invcount' => 0, 'mydata_type' => '2.1']);

        // a non-domain SC: no domain row points at it → null, zero HTTP
        $plain = ServiceContract::create([
            'company_id' => $this->company->id, 'customer_id' => $this->customer->id,
            'description' => 'Hosting', 'billing_cycle' => 'annual', 'quantity' => 1,
            'amount' => 10, 'vat_percent' => 24, 'status' => 'active',
            'start_date' => '2020-01-01', 'next_due_date' => '2026-01-01',
            'provisioning_module' => 'none',
        ]);
        $hostingInvoice = Invoice::create([
            'company_id' => $this->company->id, 'invoice_type_id' => $type->id,
            'customer_id' => $this->customer->id, 'service_contract_id' => $plain->id,
            'code' => 5, 'invcode' => 'TDA5', 'issued_at' => now(), 'local_status' => 'draft',
        ]);
        $this->assertNull(app(DomainRenewalService::class)->renewForInvoice($hostingInvoice));

        // an ok log for THIS invoice already exists → second fire is a no-op
        $invoice = Invoice::create([
            'company_id' => $this->company->id, 'invoice_type_id' => $type->id,
            'customer_id' => $this->customer->id, 'service_contract_id' => $contract->id,
            'code' => 6, 'invcode' => 'TDA6', 'issued_at' => now(), 'local_status' => 'draft',
        ]);
        DomainRegistrarLog::create([
            'company_id' => $this->company->id, 'domain_id' => $domain->id,
            'registrar_connection_id' => $this->connection->id, 'invoice_id' => $invoice->id,
            'action' => 'renew', 'status' => DomainRegistrarLog::STATUS_OK,
        ]);
        $this->assertNull(app(DomainRenewalService::class)->renewForInvoice($invoice));

        // a non-yearly cycle cannot map to a renewal term → loud refusal
        $contract->forceFill(['billing_cycle' => 'monthly'])->save();
        $monthly = Invoice::create([
            'company_id' => $this->company->id, 'invoice_type_id' => $type->id,
            'customer_id' => $this->customer->id, 'service_contract_id' => $contract->id,
            'code' => 7, 'invcode' => 'TDA7', 'issued_at' => now(), 'local_status' => 'draft',
        ]);
        try {
            app(DomainRenewalService::class)->renewForInvoice($monthly);
            $this->fail('a non-yearly cycle must refuse');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('κύκλος', $e->getMessage());
        }
    }

    public function test_issuing_the_renewal_invoice_renews_at_the_registrar_and_advances_the_cursor(): void
    {
        // The full §6.1 on-issue flow through the REAL observer: draft →
        // active fires the registrar renew (period = the SC cursor + cycle),
        // then the cursor advances — order matters and is under test here.
        Http::fake([
            self::SANDBOX.'/v1beta/auth/login' => Http::response(['data' => ['token' => 'tok']]),
            self::SANDBOX.'/v1beta/domains/77' => Http::sequence()
                ->push(['data' => $this->opDomain('2026-01-01')])
                ->push(['data' => $this->opDomain('2026-01-01')])
                ->push(['data' => $this->opDomain('2027-01-01')]),
            self::SANDBOX.'/v1beta/domains/77/renew' => Http::response(['code' => 0]),
        ]);
        [$domain, $contract] = $this->assignedDomain('2026-01-01');
        $type = InvoiceType::create(['company_id' => $this->company->id, 'code' => 'TDA', 'name' => 'ΤΔΑ', 'invcount' => 0, 'mydata_type' => '2.1']);
        $invoice = Invoice::create([
            'company_id' => $this->company->id, 'invoice_type_id' => $type->id,
            'customer_id' => $this->customer->id, 'service_contract_id' => $contract->id,
            'code' => 9, 'invcode' => 'TDA9', 'issued_at' => now(), 'local_status' => 'draft',
        ]);

        $invoice->update(['local_status' => 'active']); // Οριστικοποίηση

        $log = DomainRegistrarLog::where('invoice_id', $invoice->id)->sole();
        $this->assertSame(DomainRegistrarLog::STATUS_OK, $log->status);
        $this->assertSame('2027-01-01', $domain->refresh()->expires_at->toDateString(), 'registrar truth applied');
        $this->assertSame('2027-01-01', $contract->refresh()->next_due_date->toDateString(), 'billing cursor advanced AFTER the renew');
    }

    public function test_a_registrar_failure_on_issue_never_fails_the_issue(): void
    {
        Http::fake([
            self::SANDBOX.'/v1beta/auth/login' => Http::response(['data' => ['token' => 'tok']]),
            self::SANDBOX.'/v1beta/domains/77' => Http::response(['desc' => 'registrar down'], 500),
        ]);
        [$domain, $contract] = $this->assignedDomain('2026-01-01');
        $type = InvoiceType::create(['company_id' => $this->company->id, 'code' => 'TDA', 'name' => 'ΤΔΑ', 'invcount' => 0, 'mydata_type' => '2.1']);
        $invoice = Invoice::create([
            'company_id' => $this->company->id, 'invoice_type_id' => $type->id,
            'customer_id' => $this->customer->id, 'service_contract_id' => $contract->id,
            'code' => 11, 'invcode' => 'TDA11', 'issued_at' => now(), 'local_status' => 'draft',
        ]);

        $invoice->update(['local_status' => 'active']);

        $this->assertSame('active', $invoice->refresh()->local_status, 'the issue stands');
        $this->assertSame(DomainRegistrarLog::STATUS_FAILED, DomainRegistrarLog::where('invoice_id', $invoice->id)->sole()->status);
        $this->assertSame('2027-01-01', $contract->refresh()->next_due_date->toDateString(), 'the cursor still advances — the invoice IS issued; the operator retries the renew from the domain');
        $this->assertSame('2026-01-01', $domain->refresh()->expires_at->toDateString(), 'expiry untouched — the renewal did not happen');
    }
}
