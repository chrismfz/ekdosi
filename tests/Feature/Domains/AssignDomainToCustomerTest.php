<?php

namespace Tests\Feature\Domains;

use App\Actions\Domains\AssignDomainToCustomer;
use App\Actions\Domains\TransferDomainOwnership;
use App\Enums\BillingCycle;
use App\Enums\ServiceContractStatus;
use App\Filament\Support\StageRenewalNowAction;
use App\Models\Company;
use App\Models\Customer;
use App\Models\Domain;
use App\Models\DomainTld;
use App\Models\DomainTldPrice;
use App\Models\Invoice;
use App\Models\InvoiceType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

/**
 * Πυλώνας A / A1b — the null→customer transition: creates the 1:1
 * ServiceContract with the TLD's renewal pricing (per-domain override wins),
 * the billing clock starts at the registrar expiry, and every guard throws
 * with an actionable message. Plus «Μεταφορά ιδιοκτησίας» (customer→customer,
 * the SC follows, invoices untouched by design).
 */
class AssignDomainToCustomerTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private Customer $customer;

    private DomainTld $tld;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = Company::create([
            'name' => 'Dom', 'slug' => 'd-'.uniqid(), 'country_code' => 'GR',
            'einvoice_provider' => 'gr-mydata', 'mydata_mode' => 'off',
            'enable_domain_management' => true,
        ]);
        $this->customer = Customer::create([
            'company_id' => $this->company->id, 'name' => 'Πελάτης ΑΕ',
        ]);
        $this->tld = DomainTld::create([
            'company_id' => $this->company->id, 'tld' => 'gr', 'min_years' => 2,
        ]);
        // .gr-style: renewal sold in even years only.
        DomainTldPrice::create([
            'company_id' => $this->company->id, 'domain_tld_id' => $this->tld->id,
            'operation' => 'renewal', 'years' => 2, 'price' => 19.00,
        ]);
    }

    private function domain(array $extra = []): Domain
    {
        return Domain::create(array_merge([
            'company_id' => $this->company->id,
            'domain_tld_id' => $this->tld->id,
            'sld' => 'example', 'tld' => 'gr', 'fqdn' => 'example.gr',
            'registered_at' => '2024-03-01',
            'expires_at' => '2027-03-01',
        ], $extra));
    }

    public function test_assign_creates_the_one_to_one_contract_with_tld_renewal_pricing(): void
    {
        $domain = $this->domain();

        $assigned = app(AssignDomainToCustomer::class)($domain, $this->customer, vatPercent: 24.0);

        $sc = $assigned->serviceContract;
        $this->assertNotNull($sc);
        $this->assertSame($this->customer->id, $assigned->customer_id);
        $this->assertSame($this->customer->id, $sc->customer_id);
        $this->assertSame('19.00', (string) $sc->amount);
        $this->assertSame(BillingCycle::Biennial->value, $sc->billing_cycle->value ?? $sc->billing_cycle);
        // The billing clock starts at the registrar expiry (two-clocks rule).
        $this->assertSame('2027-03-01', $sc->next_due_date->toDateString());
        $this->assertSame(ServiceContractStatus::Active, $sc->status);
        $this->assertSame('example.gr', $sc->domain);
    }

    public function test_per_domain_price_override_wins(): void
    {
        $domain = $this->domain(['fqdn' => 'other.gr', 'sld' => 'other', 'price_override' => 25.50]);

        $assigned = app(AssignDomainToCustomer::class)($domain, $this->customer);

        $this->assertSame('25.50', (string) $assigned->serviceContract->amount);
    }

    public function test_assign_refuses_without_a_registrar_expiry(): void
    {
        // Without a registrar expiry, next_due would fall to today and stage a
        // renewal draft TONIGHT — never guess a billing date.
        $domain = $this->domain(['fqdn' => 'noexp.gr', 'sld' => 'noexp', 'expires_at' => null]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('ημερομηνία λήξης');
        app(AssignDomainToCustomer::class)($domain, $this->customer);
    }

    public function test_catalogue_pricing_respects_the_tlds_minimum_term(): void
    {
        // A stray 1yr row on a min_years=2 TLD (.gr sells biennial only) must
        // not create an Annual contract — the 2yr row wins.
        DomainTldPrice::create([
            'company_id' => $this->company->id, 'domain_tld_id' => $this->tld->id,
            'operation' => 'renewal', 'years' => 1, 'price' => 10.00,
        ]);
        $domain = $this->domain(['fqdn' => 'minterm.gr', 'sld' => 'minterm']);

        $assigned = app(AssignDomainToCustomer::class)($domain, $this->customer);

        $sc = $assigned->serviceContract;
        $this->assertSame('19.00', (string) $sc->amount);
        $this->assertSame(BillingCycle::Biennial->value, $sc->billing_cycle->value ?? $sc->billing_cycle);
    }

    public function test_assign_refuses_a_terminal_status_domain(): void
    {
        // transferred_away = we no longer hold it — an Active billing clock
        // here would bill the customer for someone else's domain.
        $domain = $this->domain(['fqdn' => 'gone.gr', 'sld' => 'gone', 'status' => 'transferred_away']);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('δεν ξεκινά χρέωση');
        app(AssignDomainToCustomer::class)($domain, $this->customer);
    }

    public function test_assign_refuses_an_already_assigned_domain(): void
    {
        $domain = $this->domain(['customer_id' => $this->customer->id]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('ήδη ανατεθεί');
        app(AssignDomainToCustomer::class)($domain, $this->customer);
    }

    public function test_assign_refuses_without_an_enabled_renewal_price(): void
    {
        DomainTldPrice::query()->delete();
        $domain = $this->domain();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('τιμή ανανέωσης');
        app(AssignDomainToCustomer::class)($domain, $this->customer);
    }

    public function test_assign_refuses_a_customer_of_another_tenant(): void
    {
        $other = Company::create([
            'name' => 'Other', 'slug' => 'o-'.uniqid(), 'country_code' => 'GR',
            'einvoice_provider' => 'gr-mydata', 'mydata_mode' => 'off',
        ]);
        $foreign = Customer::create(['company_id' => $other->id, 'name' => 'Ξένος']);
        $domain = $this->domain();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('άλλη εταιρεία');
        app(AssignDomainToCustomer::class)($domain, $foreign);
    }

    public function test_ownership_transfer_moves_domain_and_contract_to_the_new_customer(): void
    {
        $domain = $this->domain();
        app(AssignDomainToCustomer::class)($domain, $this->customer);

        $newCustomer = Customer::create(['company_id' => $this->company->id, 'name' => 'Νέος Πελάτης']);
        $moved = app(TransferDomainOwnership::class)($domain->refresh(), $newCustomer);

        $this->assertSame($newCustomer->id, $moved->customer_id);
        $this->assertSame($newCustomer->id, $moved->serviceContract->customer_id);
    }

    public function test_ownership_transfer_refuses_while_an_open_draft_renewal_exists(): void
    {
        $domain = $this->domain(['fqdn' => 'draft.gr', 'sld' => 'draft']);
        app(AssignDomainToCustomer::class)($domain, $this->customer);
        $domain->refresh();

        // A staged-but-unissued renewal draft on the CURRENT customer.
        $type = InvoiceType::create([
            'company_id' => $this->company->id, 'code' => 'TDA', 'name' => 'Τιμολόγιο', 'invcount' => 1,
        ]);
        Invoice::create([
            'company_id' => $this->company->id, 'invoice_type_id' => $type->id,
            'customer_id' => $this->customer->id, 'service_contract_id' => $domain->service_contract_id,
            'code' => 1, 'invcode' => 'TDA1', 'issued_at' => now(), 'local_status' => 'draft',
        ]);

        $newCustomer = Customer::create(['company_id' => $this->company->id, 'name' => 'Νέος']);
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('πρόχειρο παραστατικό');
        app(TransferDomainOwnership::class)($domain, $newCustomer);
    }

    public function test_assign_turns_auto_renew_on_and_lapse_domains_skip_silently(): void
    {
        // Assignment = intent to bill → auto_renew ON.
        $domain = $this->domain(['fqdn' => 'lapse.gr', 'sld' => 'lapse']);
        app(AssignDomainToCustomer::class)($domain, $this->customer);
        $domain->refresh();
        $this->assertTrue($domain->auto_renew);

        // Operator flips it off (owner decision β): due date arrives → NO
        // draft, NO per-row nagging — the domain is meant to lapse.
        $domain->update(['auto_renew' => false]);
        $domain->serviceContract->forceFill(['next_due_date' => now()->subDay()->toDateString()])->save();

        $this->artisan('services:stage-renewals', ['--tenant' => $this->company->slug])
            ->expectsOutputToContain('χωρίς αυτόματη ανανέωση')
            ->doesntExpectOutputToContain('δεν χρεώνουμε')
            ->assertExitCode(0);

        $this->assertSame(0, Invoice::query()
            ->where('company_id', $this->company->id)
            ->where('service_contract_id', $domain->service_contract_id)
            ->count());
    }

    public function test_stage_renewal_now_bills_early_and_respects_the_dead_set(): void
    {
        $type = InvoiceType::create([
            'company_id' => $this->company->id, 'code' => 'TDN', 'name' => 'Τιμολόγιο', 'invcount' => 1,
        ]);
        $domain = $this->domain(['fqdn' => 'early.gr', 'sld' => 'early']);
        app(AssignDomainToCustomer::class)($domain, $this->customer, invoiceTypeId: $type->id);
        $domain->refresh();

        // Early on-demand: due 2027 but the customer wants to renew NOW —
        // and explicit intent bypasses auto_renew=off (the let-lapse default
        // is about the automatic sweep only).
        $domain->update(['auto_renew' => false]);
        $draft = StageRenewalNowAction::stageNow($domain->serviceContract);

        $this->assertNotNull($draft);
        $this->assertSame('draft', $draft->local_status);
        // The cursor does NOT move at staging — it advances when the draft is
        // ISSUED (InvoiceObserver); until then the open-draft guard blocks dupes.
        $this->assertSame('2027-03-01', $domain->serviceContract->fresh()->next_due_date->toDateString());

        // Second click: the open draft blocks a duplicate (null, not a second doc).
        $this->assertNull(StageRenewalNowAction::stageNow($domain->serviceContract->fresh()));

        // Dead domain: unbillable from every path, on-demand included.
        $domain->update(['status' => 'transferred_away']);
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('δεν κατέχουμε');
        StageRenewalNowAction::stageNow($domain->serviceContract->fresh());
    }

    public function test_ownership_transfer_refuses_a_terminal_domain(): void
    {
        // Same money-direction guard as assign: a dead name's Active contract
        // must not be moved onto (and bill) a new customer.
        $domain = $this->domain(['fqdn' => 'dead.gr', 'sld' => 'dead']);
        app(AssignDomainToCustomer::class)($domain, $this->customer);
        $domain->refresh()->update(['status' => 'transferred_away']);

        $newCustomer = Customer::create(['company_id' => $this->company->id, 'name' => 'Νέος']);
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('δεν μεταφέρεται');
        app(TransferDomainOwnership::class)($domain->refresh(), $newCustomer);
    }

    public function test_renewal_staging_skips_a_dead_domains_contract(): void
    {
        $domain = $this->domain(['fqdn' => 'stale.gr', 'sld' => 'stale']);
        app(AssignDomainToCustomer::class)($domain, $this->customer);
        $domain->refresh();

        // Contract due yesterday, but the domain is gone — no draft, no charge.
        $domain->serviceContract->forceFill(['next_due_date' => now()->subDay()->toDateString()])->save();
        $domain->update(['status' => 'transferred_away']);

        $this->artisan('services:stage-renewals', ['--tenant' => $this->company->slug])
            ->expectsOutputToContain('δεν χρεώνουμε')
            ->assertExitCode(0);

        $this->assertSame(0, Invoice::query()
            ->where('company_id', $this->company->id)
            ->where('service_contract_id', $domain->service_contract_id)
            ->count(), 'κανένα draft για domain που δεν κατέχουμε');
    }

    public function test_ownership_transfer_refuses_unassigned_or_same_customer(): void
    {
        $unassigned = $this->domain(['fqdn' => 'loose.gr', 'sld' => 'loose']);
        try {
            app(TransferDomainOwnership::class)($unassigned, $this->customer);
            $this->fail('unassigned πρέπει να απορρίπτεται');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('Ανάθεση', $e->getMessage());
        }

        $assigned = $this->domain(['fqdn' => 'mine.gr', 'sld' => 'mine', 'customer_id' => $this->customer->id]);
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('ήδη');
        app(TransferDomainOwnership::class)($assigned, $this->customer);
    }
}
