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
            // 2 GETs: the §6.6 pre-check sync, then the post-renew re-fetch
            // (renew() reuses the freshly-synced stored id — no resolve GET)
            self::SANDBOX.'/v1beta/domains/77' => Http::sequence()
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

    public function test_drifted_cursor_still_adopts_by_consuming_the_button_renewal_log(): void
    {
        // The date guard's blind spot (review r1): expiry 2026-05-01 but the
        // SC cursor operator-edited to 2026-06-15. The button renewed →
        // registrar 2027-05-01 < cursor+1y — a pure date compare would renew
        // AGAIN. The intent-match leg consumes the button's unconsumed OK log
        // instead: adopted, ZERO registrar traffic.
        [$domain, $contract] = $this->assignedDomain('2026-05-01');
        $contract->forceFill(['next_due_date' => '2026-06-15'])->save();
        $domain->forceFill(['expires_at' => '2027-05-01'])->save(); // the button moved it
        $buttonLog = DomainRegistrarLog::create([
            'company_id' => $this->company->id, 'domain_id' => $domain->id,
            'registrar_connection_id' => $this->connection->id, 'invoice_id' => null,
            'action' => 'renew', 'status' => DomainRegistrarLog::STATUS_OK,
            'request' => ['fqdn' => 'example.gr', 'years' => 1],
        ]);

        $type = InvoiceType::create(['company_id' => $this->company->id, 'code' => 'TDA', 'name' => 'ΤΔΑ', 'invcount' => 0, 'mydata_type' => '2.1']);
        $invoice = Invoice::create([
            'company_id' => $this->company->id, 'invoice_type_id' => $type->id,
            'customer_id' => $this->customer->id, 'service_contract_id' => $contract->id,
            'code' => 2, 'invcode' => 'TDA2', 'issued_at' => now(), 'local_status' => 'draft',
        ]);

        Http::fake(); // preventStrayRequests + no fakes = ANY http call fails the test

        $log = app(DomainRenewalService::class)->renewForInvoice($invoice);

        $this->assertSame(DomainRegistrarLog::STATUS_ADOPTED, $log->status);
        $this->assertSame($invoice->id, $buttonLog->refresh()->invoice_id, 'the button renewal is consumed by this invoice');
        Http::assertNothingSent();
    }

    public function test_reissue_after_revert_steps_the_period_back_and_adopts_a_panel_renewal(): void
    {
        // The revert door (review r2): issue #1 fails at the registrar but the
        // cursor advances; someone renews at the registrar PANEL; the operator
        // reverts to draft and re-issues. The capture must step the period
        // back one cycle (the cursor already advanced FOR this invoice) so the
        // pre-check sees the covered period and ADOPTS — not renew again.
        Http::fake([
            self::SANDBOX.'/v1beta/auth/login' => Http::response(['data' => ['token' => 'tok']]),
            self::SANDBOX.'/v1beta/domains/77' => Http::sequence()
                ->push(['desc' => 'down'], 500)                   // issue #1 pre-check → failed
                ->push(['data' => $this->opDomain('2027-01-01')]), // re-issue pre-check → panel-renewed
        ]);
        [$domain, $contract] = $this->assignedDomain('2026-01-01');
        $type = InvoiceType::create(['company_id' => $this->company->id, 'code' => 'TDA', 'name' => 'ΤΔΑ', 'invcount' => 0, 'mydata_type' => '2.1']);
        $invoice = Invoice::create([
            'company_id' => $this->company->id, 'invoice_type_id' => $type->id,
            'customer_id' => $this->customer->id, 'service_contract_id' => $contract->id,
            'code' => 4, 'invcode' => 'TDA4', 'issued_at' => now(), 'local_status' => 'draft',
        ]);

        $invoice->update(['local_status' => 'active']); // issue #1: renew fails, cursor 2026→2027
        $this->assertSame('2027-01-01', $contract->refresh()->next_due_date->toDateString());
        $this->assertSame(DomainRegistrarLog::STATUS_FAILED, DomainRegistrarLog::latest('id')->first()->status);

        $invoice->update(['local_status' => 'draft']);  // Επαναφορά σε πρόχειρο (cursor stays)
        $invoice->update(['local_status' => 'active']); // re-issue

        $adopted = DomainRegistrarLog::where('status', DomainRegistrarLog::STATUS_ADOPTED)->sole();
        $this->assertSame('2027-01-01', $adopted->request['target_expiry'], 'period stepped back: target is the ORIGINAL billed period');
        Http::assertNotSent(fn ($req) => str_contains($req->url(), '/renew'));
        $this->assertSame('2027-01-01', $domain->refresh()->expires_at->toDateString());
    }

    public function test_a_consumed_button_log_is_never_consumed_twice(): void
    {
        // CAS semantics: a log already tied to an invoice can't satisfy a
        // second invoice — that one proceeds to a REAL renew.
        Http::fake([
            self::SANDBOX.'/v1beta/auth/login' => Http::response(['data' => ['token' => 'tok']]),
            self::SANDBOX.'/v1beta/domains/77' => Http::sequence()
                ->push(['data' => $this->opDomain('2026-01-01')])
                ->push(['data' => $this->opDomain('2027-01-01')]),
            self::SANDBOX.'/v1beta/domains/77/renew' => Http::response(['code' => 0]),
        ]);
        [$domain, $contract] = $this->assignedDomain('2026-01-01');
        $type = InvoiceType::create(['company_id' => $this->company->id, 'code' => 'TDA', 'name' => 'ΤΔΑ', 'invcount' => 0, 'mydata_type' => '2.1']);
        $earlier = Invoice::create([
            'company_id' => $this->company->id, 'invoice_type_id' => $type->id,
            'customer_id' => $this->customer->id,
            'code' => 7, 'invcode' => 'TDA7', 'issued_at' => now(), 'local_status' => 'active',
        ]);
        DomainRegistrarLog::create([
            'company_id' => $this->company->id, 'domain_id' => $domain->id,
            'registrar_connection_id' => $this->connection->id, 'invoice_id' => $earlier->id, // consumed elsewhere
            'action' => 'renew', 'status' => DomainRegistrarLog::STATUS_OK,
            'request' => ['fqdn' => 'example.gr', 'years' => 1],
        ]);
        $invoice = Invoice::create([
            'company_id' => $this->company->id, 'invoice_type_id' => $type->id,
            'customer_id' => $this->customer->id, 'service_contract_id' => $contract->id,
            'code' => 8, 'invcode' => 'TDA8', 'issued_at' => now(), 'local_status' => 'draft',
        ]);

        $log = app(DomainRenewalService::class)->renewForInvoice($invoice);

        $this->assertSame(DomainRegistrarLog::STATUS_OK, $log->status, 'a real renew ran');
        Http::assertSent(fn ($req) => str_contains($req->url(), '/renew'));
    }

    public function test_a_date_adopt_also_stamps_the_unconsumed_button_log(): void
    {
        // Biennial invoice, 1yr button log (can't intent-match) — but the
        // registrar already covers the biennial target, so the date leg
        // adopts AND must consume the button log: its year is spent, a later
        // intent-match must not count it again (review r2 finding 3).
        Http::fake([
            self::SANDBOX.'/v1beta/auth/login' => Http::response(['data' => ['token' => 'tok']]),
            self::SANDBOX.'/v1beta/domains/77' => Http::response(['data' => $this->opDomain('2028-01-01')]),
        ]);
        [$domain, $contract] = $this->assignedDomain('2026-01-01');
        $contract->forceFill(['billing_cycle' => 'biennial'])->save();
        // TWO 1yr button renewals fed the 2yr coverage — BOTH must be consumed
        // (a leftover would satisfy a later intent-match with no year behind it).
        $logA = DomainRegistrarLog::create([
            'company_id' => $this->company->id, 'domain_id' => $domain->id,
            'registrar_connection_id' => $this->connection->id, 'invoice_id' => null,
            'action' => 'renew', 'status' => DomainRegistrarLog::STATUS_OK,
            'request' => ['fqdn' => 'example.gr', 'years' => 1],
        ]);
        $logB = DomainRegistrarLog::create([
            'company_id' => $this->company->id, 'domain_id' => $domain->id,
            'registrar_connection_id' => $this->connection->id, 'invoice_id' => null,
            'action' => 'renew', 'status' => DomainRegistrarLog::STATUS_OK,
            'request' => ['fqdn' => 'example.gr', 'years' => 1],
        ]);
        $type = InvoiceType::create(['company_id' => $this->company->id, 'code' => 'TDA', 'name' => 'ΤΔΑ', 'invcount' => 0, 'mydata_type' => '2.1']);
        $invoice = Invoice::create([
            'company_id' => $this->company->id, 'invoice_type_id' => $type->id,
            'customer_id' => $this->customer->id, 'service_contract_id' => $contract->id,
            'code' => 12, 'invcode' => 'TDA12', 'issued_at' => now(), 'local_status' => 'draft',
        ]);

        $log = app(DomainRenewalService::class)->renewForInvoice($invoice);

        $this->assertSame(DomainRegistrarLog::STATUS_ADOPTED, $log->status);
        $this->assertSame($invoice->id, $logA->refresh()->invoice_id, 'first button year consumed');
        $this->assertSame($invoice->id, $logB->refresh()->invoice_id, 'second button year consumed too');
        $this->assertCount(2, $log->response['consumed_log_ids']);
        Http::assertNotSent(fn ($req) => str_contains($req->url(), '/renew'));
    }

    public function test_a_stale_orphan_button_log_cannot_satisfy_todays_invoice(): void
    {
        // A 2-years-old unconsumed OK log (its registrar year long spent) must
        // NOT intent-match today's invoice — stale logs fall through to the
        // sync-backed date check, which renews for real (r3 finding 2).
        Http::fake([
            self::SANDBOX.'/v1beta/auth/login' => Http::response(['data' => ['token' => 'tok']]),
            self::SANDBOX.'/v1beta/domains/77' => Http::sequence()
                ->push(['data' => $this->opDomain('2026-01-01')])
                ->push(['data' => $this->opDomain('2027-01-01')]),
            self::SANDBOX.'/v1beta/domains/77/renew' => Http::response(['code' => 0]),
        ]);
        [$domain, $contract] = $this->assignedDomain('2026-01-01');
        $stale = DomainRegistrarLog::create([
            'company_id' => $this->company->id, 'domain_id' => $domain->id,
            'registrar_connection_id' => $this->connection->id, 'invoice_id' => null,
            'action' => 'renew', 'status' => DomainRegistrarLog::STATUS_OK,
            'request' => ['fqdn' => 'example.gr', 'years' => 1],
        ]);
        DomainRegistrarLog::whereKey($stale->id)->update(['created_at' => now()->subYears(2)]);

        $type = InvoiceType::create(['company_id' => $this->company->id, 'code' => 'TDA', 'name' => 'ΤΔΑ', 'invcount' => 0, 'mydata_type' => '2.1']);
        $invoice = Invoice::create([
            'company_id' => $this->company->id, 'invoice_type_id' => $type->id,
            'customer_id' => $this->customer->id, 'service_contract_id' => $contract->id,
            'code' => 14, 'invcode' => 'TDA14', 'issued_at' => now(), 'local_status' => 'draft',
        ]);

        $log = app(DomainRenewalService::class)->renewForInvoice($invoice);

        $this->assertSame(DomainRegistrarLog::STATUS_OK, $log->status, 'a REAL renew ran');
        Http::assertSent(fn ($req) => str_contains($req->url(), '/renew'));
        $this->assertNull($stale->refresh()->invoice_id, 'the stale orphan stays for the A5 reconciler');
    }

    public function test_reissue_recovers_the_billed_period_from_its_own_prior_log(): void
    {
        // The interleaved-invoice hole (r3 finding 1): A fails (cursor moves),
        // panel covers A's period, B issues+renews (cursor moves again, the
        // last_renewal step-back no longer matches A) — A's re-issue must
        // recover ITS period from its own failed log, not the cursor.
        Http::fake([
            self::SANDBOX.'/v1beta/auth/login' => Http::response(['data' => ['token' => 'tok']]),
            self::SANDBOX.'/v1beta/domains/77' => Http::sequence()
                ->push(['desc' => 'down'], 500)                    // A issue #1 pre-check → failed
                ->push(['data' => $this->opDomain('2027-01-01')])  // B pre-check (panel covered A's year)
                ->push(['data' => $this->opDomain('2028-01-01')])  // B post-renew re-fetch
                ->push(['data' => $this->opDomain('2028-01-01')]), // A re-issue pre-check
            self::SANDBOX.'/v1beta/domains/77/renew' => Http::response(['code' => 0]),
        ]);
        [$domain, $contract] = $this->assignedDomain('2026-01-01');
        $type = InvoiceType::create(['company_id' => $this->company->id, 'code' => 'TDA', 'name' => 'ΤΔΑ', 'invcount' => 0, 'mydata_type' => '2.1']);
        $invoiceA = Invoice::create([
            'company_id' => $this->company->id, 'invoice_type_id' => $type->id,
            'customer_id' => $this->customer->id, 'service_contract_id' => $contract->id,
            'code' => 15, 'invcode' => 'TDA15', 'issued_at' => now(), 'local_status' => 'draft',
        ]);

        $invoiceA->update(['local_status' => 'active']); // fails at registrar; cursor 2026→2027
        $invoiceA->update(['local_status' => 'draft']);  // Επαναφορά

        $invoiceB = Invoice::create([
            'company_id' => $this->company->id, 'invoice_type_id' => $type->id,
            'customer_id' => $this->customer->id, 'service_contract_id' => $contract->id,
            'code' => 16, 'invcode' => 'TDA16', 'issued_at' => now(), 'local_status' => 'draft',
        ]);
        $invoiceB->update(['local_status' => 'active']); // renews 2027→2028; cursor → 2028

        $invoiceA->update(['local_status' => 'active']); // A re-issues

        $adopted = DomainRegistrarLog::where('invoice_id', $invoiceA->id)
            ->where('status', DomainRegistrarLog::STATUS_ADOPTED)->sole();
        $this->assertSame('2027-01-01', $adopted->request['target_expiry'], 'A period recovered from its OWN failed log');
        // exactly ONE real renew in the whole story — B's
        $this->assertSame(1, DomainRegistrarLog::where('status', DomainRegistrarLog::STATUS_OK)->count());
        $this->assertSame('2028-01-01', $domain->refresh()->expires_at->toDateString());
    }

    public function test_a_locked_domain_on_issue_warns_do_not_renew_again(): void
    {
        // The button holds the lock while the invoice issues: the hook must
        // refuse QUIETLY-BUT-VISIBLY with «μην ξαναζητήσετε», never advise a
        // retry (the button's baseline can never adopt — it would double).
        [$domain, $contract] = $this->assignedDomain('2026-01-01');
        $lock = Cache::lock('domains:renew:'.$domain->id, 60);
        $this->assertTrue($lock->get());

        try {
            Http::fake();
            $type = InvoiceType::create(['company_id' => $this->company->id, 'code' => 'TDA', 'name' => 'ΤΔΑ', 'invcount' => 0, 'mydata_type' => '2.1']);
            $invoice = Invoice::create([
                'company_id' => $this->company->id, 'invoice_type_id' => $type->id,
                'customer_id' => $this->customer->id, 'service_contract_id' => $contract->id,
                'code' => 13, 'invcode' => 'TDA13', 'issued_at' => now(), 'local_status' => 'draft',
            ]);

            $invoice->update(['local_status' => 'active']);

            $this->assertSame('active', $invoice->refresh()->local_status, 'the issue stands');
            Http::assertNothingSent();
            $this->assertSame(0, DomainRegistrarLog::count(), 'the lock refused before any registrar traffic/logging');
        } finally {
            $lock->release();
        }
    }

    public function test_restoring_a_cancelled_invoice_never_fires_an_implicit_renew(): void
    {
        // A pre-feature (or any) cancelled invoice restored to active must not
        // charge the registrar — renew-on-issue means draft→active ONLY.
        [, $contract] = $this->assignedDomain('2026-01-01');
        $type = InvoiceType::create(['company_id' => $this->company->id, 'code' => 'TDA', 'name' => 'ΤΔΑ', 'invcount' => 0, 'mydata_type' => '2.1']);
        $invoice = Invoice::create([
            'company_id' => $this->company->id, 'invoice_type_id' => $type->id,
            'customer_id' => $this->customer->id, 'service_contract_id' => $contract->id,
            'code' => 3, 'invcode' => 'TDA3', 'issued_at' => now(), 'local_status' => 'cancelled',
        ]);

        Http::fake();
        $invoice->update(['local_status' => 'active']); // Επαναφορά

        Http::assertNothingSent();
        $this->assertSame(0, DomainRegistrarLog::count());
    }

    public function test_a_concurrent_renewal_is_refused_by_the_per_domain_lock(): void
    {
        [$domain] = $this->assignedDomain();
        $lock = Cache::lock('domains:renew:'.$domain->id, 60);
        $this->assertTrue($lock->get());

        try {
            Http::fake();
            try {
                app(DomainRenewalService::class)->renew($domain, 1);
                $this->fail('a concurrent renewal must refuse');
            } catch (RuntimeException $e) {
                $this->assertStringContainsString('σε εξέλιξη', $e->getMessage());
            }
            Http::assertNothingSent();
        } finally {
            $lock->release();
        }
    }

    public function test_a_sync_that_reveals_a_dead_domain_aborts_the_renew(): void
    {
        // Local status stale-Active; the pre-check sync flips it to Deleted
        // (registrar DEL) — «νεκρό όνομα = ποτέ χρέωση» must hold here too.
        Http::fake([
            self::SANDBOX.'/v1beta/auth/login' => Http::response(['data' => ['token' => 'tok']]),
            self::SANDBOX.'/v1beta/domains/77' => Http::response(['data' => [
                'id' => 77, 'status' => 'DEL', 'expiration_date' => '2026-01-01 00:00:00', 'name_servers' => [],
            ]]),
        ]);
        [$domain] = $this->assignedDomain();

        try {
            app(DomainRenewalService::class)->renew($domain, 1);
            $this->fail('a dead name must never be charged');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('Διαγραμμένο', $e->getMessage());
        }
        Http::assertNotSent(fn ($req) => str_contains($req->url(), '/renew'));
        $this->assertSame(DomainRegistrarLog::STATUS_FAILED, DomainRegistrarLog::sole()->status);
    }

    public function test_a_post_renew_refetch_failure_still_logs_ok_never_failed(): void
    {
        // From the POST on, the registrar HAS charged — a re-fetch hiccup must
        // not misrecord a real charge as failed (review r1 finding 5).
        Http::fake([
            self::SANDBOX.'/v1beta/auth/login' => Http::response(['data' => ['token' => 'tok']]),
            self::SANDBOX.'/v1beta/domains/77' => Http::sequence()
                ->push(['data' => $this->opDomain('2026-01-01')]) // pre-check sync
                ->push(['desc' => 'boom'], 502),                  // post-renew re-fetch
            self::SANDBOX.'/v1beta/domains/77/renew' => Http::response(['code' => 0]),
        ]);
        [$domain] = $this->assignedDomain();

        $log = app(DomainRenewalService::class)->renew($domain, 1);

        $this->assertSame(DomainRegistrarLog::STATUS_OK, $log->status);
        $this->assertNull($log->response['short_of_target'], 'unknown, not false — the A5 reconciler re-evaluates');
        $this->assertSame('2026-01-01', $domain->refresh()->expires_at->toDateString(), 'expiry lands with the nightly sync');
    }

    public function test_a_renew_landing_short_of_the_billed_target_is_flagged(): void
    {
        // Registrar expiry lagged the cursor (a prior failed renew never
        // retried): the charge is real → 'ok', but flagged so the operator
        // sees the domain will lapse before billing says.
        Http::fake([
            self::SANDBOX.'/v1beta/auth/login' => Http::response(['data' => ['token' => 'tok']]),
            self::SANDBOX.'/v1beta/domains/77' => Http::sequence()
                ->push(['data' => $this->opDomain('2026-01-01')])
                ->push(['data' => $this->opDomain('2027-01-01')]),
            self::SANDBOX.'/v1beta/domains/77/renew' => Http::response(['code' => 0]),
        ]);
        [$domain] = $this->assignedDomain('2026-01-01');

        // the invoice bills the period starting at the (drifted-ahead) cursor
        $log = app(DomainRenewalService::class)->renew($domain, 1, null, '2027-01-01');

        $this->assertSame(DomainRegistrarLog::STATUS_OK, $log->status);
        $this->assertTrue($log->response['short_of_target']);
        $this->assertSame('2028-01-01', $log->request['target_expiry']);
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
