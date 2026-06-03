<?php

namespace Tests\Feature\Services;

use App\Enums\BillingCycle;
use App\Enums\ServiceContractStatus;
use App\Models\Company;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\InvoiceType;
use App\Models\PaymentMethod;
use App\Models\ServiceContract;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * services:stage-renewals — per-tenant sweep that stages DRAFT renewal invoices
 * for due contracts. Robust against a missing invoice type (skip, not crash),
 * idempotent, --dry-run safe, and tenant-isolated via --tenant.
 */
class StageServiceRenewalsCommandTest extends TestCase
{
    use RefreshDatabase;

    private Company $tenant;

    private Customer $customer;

    private InvoiceType $type;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Company::create(['name' => 'Rec', 'slug' => 'rec-'.uniqid(), 'country_code' => 'GR']);
        $this->customer = Customer::create([
            'company_id' => $this->tenant->id, 'name' => 'Πελάτης ΑΕ', 'afm' => '123456789',
            'address1' => 'Οδός 1', 'city' => 'Αθήνα', 'postcode' => '11111', 'country' => 'GR',
        ]);
        $this->type = InvoiceType::create([
            'company_id' => $this->tenant->id, 'code' => 'ΤΠΥ', 'name' => 'Τιμολόγιο Παροχής',
            'invcount' => 1, 'mydata_type' => '2.1',
        ]);
        PaymentMethod::create(['company_id' => $this->tenant->id, 'description' => 'Μετρητά', 'due_days' => 0]);
    }

    private function makeContract(array $overrides = [], ?Company $company = null, ?Customer $customer = null, ?InvoiceType $type = null): ServiceContract
    {
        $company ??= $this->tenant;

        return ServiceContract::create(array_merge([
            'company_id' => $company->id,
            'customer_id' => ($customer ?? $this->customer)->id,
            'invoice_type_id' => ($type ?? $this->type)->id,
            'description' => 'Hosting',
            'billing_cycle' => BillingCycle::Annual->value,
            'amount' => 100,
            'quantity' => 1,
            'vat_percent' => 24,
            'status' => ServiceContractStatus::Active->value,
            'start_date' => Carbon::today()->subYear(),
            'next_due_date' => Carbon::yesterday(),
        ], $overrides));
    }

    public function test_stages_a_draft_for_each_due_contract(): void
    {
        $this->makeContract(['description' => 'A']);
        $this->makeContract(['description' => 'B']);

        $this->artisan('services:stage-renewals', ['--tenant' => $this->tenant->slug])
            ->assertExitCode(0);

        $this->assertSame(2, Invoice::where('company_id', $this->tenant->id)->where('local_status', 'draft')->count());
    }

    public function test_dry_run_stages_nothing(): void
    {
        $this->makeContract();

        $this->artisan('services:stage-renewals', ['--tenant' => $this->tenant->slug, '--dry-run' => true])
            ->assertExitCode(0);

        $this->assertSame(0, Invoice::where('company_id', $this->tenant->id)->count());
    }

    public function test_a_contract_without_invoice_type_is_skipped_not_crashing(): void
    {
        $this->makeContract(['description' => 'good']);
        $this->makeContract(['description' => 'no-type', 'invoice_type_id' => null]);

        $this->artisan('services:stage-renewals', ['--tenant' => $this->tenant->slug])
            ->assertExitCode(0);

        // Only the well-configured contract got a draft; the typeless one was
        // skipped (no exception bubbled up).
        $this->assertSame(1, Invoice::where('company_id', $this->tenant->id)->count());
    }

    public function test_is_idempotent_on_a_second_run(): void
    {
        $this->makeContract();

        $this->artisan('services:stage-renewals', ['--tenant' => $this->tenant->slug])->assertExitCode(0);
        $this->artisan('services:stage-renewals', ['--tenant' => $this->tenant->slug])->assertExitCode(0);

        $this->assertSame(1, Invoice::where('company_id', $this->tenant->id)->count());
    }

    public function test_respects_tenant_isolation(): void
    {
        // A second tenant with its own due contract.
        $other = Company::create(['name' => 'Other', 'slug' => 'other-'.uniqid(), 'country_code' => 'GR']);
        $otherCustomer = Customer::create([
            'company_id' => $other->id, 'name' => 'Άλλος', 'afm' => '999999999',
            'address1' => 'Οδός 2', 'city' => 'Θεσ/νίκη', 'postcode' => '54600', 'country' => 'GR',
        ]);
        $otherType = InvoiceType::create([
            'company_id' => $other->id, 'code' => 'ΤΠΥ', 'name' => 'Τιμ', 'invcount' => 1, 'mydata_type' => '2.1',
        ]);
        $this->makeContract([], $other, $otherCustomer, $otherType);

        // This tenant's contract.
        $this->makeContract();

        $this->artisan('services:stage-renewals', ['--tenant' => $this->tenant->slug])->assertExitCode(0);

        // Only this tenant's contract was staged; the other tenant is untouched.
        $this->assertSame(1, Invoice::where('company_id', $this->tenant->id)->count());
        $this->assertSame(0, Invoice::where('company_id', $other->id)->count());
    }

    public function test_unknown_tenant_slug_returns_invalid(): void
    {
        $this->artisan('services:stage-renewals', ['--tenant' => 'does-not-exist'])
            ->assertExitCode(2);
    }

    public function test_lead_days_stages_contracts_due_within_the_window(): void
    {
        // Not due today, but due in 5 days — staged only with --lead-days>=5.
        $this->makeContract(['next_due_date' => Carbon::today()->addDays(5)]);

        $this->artisan('services:stage-renewals', ['--tenant' => $this->tenant->slug])
            ->assertExitCode(0);
        $this->assertSame(0, Invoice::where('company_id', $this->tenant->id)->count());

        $this->artisan('services:stage-renewals', ['--tenant' => $this->tenant->slug, '--lead-days' => 7])
            ->assertExitCode(0);
        $this->assertSame(1, Invoice::where('company_id', $this->tenant->id)->count());
    }
}
