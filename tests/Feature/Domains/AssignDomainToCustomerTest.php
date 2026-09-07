<?php

namespace Tests\Feature\Domains;

use App\Actions\Domains\AssignDomainToCustomer;
use App\Actions\Domains\TransferDomainOwnership;
use App\Enums\BillingCycle;
use App\Enums\ServiceContractStatus;
use App\Models\Company;
use App\Models\Customer;
use App\Models\Domain;
use App\Models\DomainTld;
use App\Models\DomainTldPrice;
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
