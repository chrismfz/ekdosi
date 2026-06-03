<?php

namespace Tests\Feature\Services;

use App\Enums\BillingCycle;
use App\Enums\ServiceContractStatus;
use App\Models\Company;
use App\Models\Customer;
use App\Models\ServiceContract;
use App\Services\ServiceContractInsights;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * ServiceContractInsights — the testable home of the dashboard MRR/renewal
 * figures. The MRR sum normalises each Active recurring contract to a per-month
 * value; Suspended / One-Time / other-tenant contracts are excluded.
 */
class ServiceContractStatsTest extends TestCase
{
    use RefreshDatabase;

    private Company $tenant;

    private Customer $customer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Company::create(['name' => 'Rec', 'slug' => 'rec-'.uniqid(), 'country_code' => 'GR']);
        $this->customer = Customer::create([
            'company_id' => $this->tenant->id, 'name' => 'Πελάτης', 'afm' => '123456789',
            'address1' => 'Οδός 1', 'city' => 'Αθήνα', 'postcode' => '11111', 'country' => 'GR',
        ]);
    }

    private function contract(array $overrides = []): ServiceContract
    {
        return ServiceContract::create(array_merge([
            'company_id' => $this->tenant->id,
            'customer_id' => $this->customer->id,
            'description' => 'svc',
            'billing_cycle' => BillingCycle::Annual->value,
            'amount' => 120,
            'quantity' => 1,
            'vat_percent' => 24,
            'status' => ServiceContractStatus::Active->value,
            'next_due_date' => Carbon::today()->addDays(10),
        ], $overrides));
    }

    public function test_mrr_is_the_sum_of_normalised_per_month_recurring_revenue(): void
    {
        // Annual €120 qty 1 → 120/12 = 10.0/month.
        $this->contract(['billing_cycle' => BillingCycle::Annual->value, 'amount' => 120, 'quantity' => 1]);
        // Monthly €30 qty 2 → 30*2/1 = 60.0/month.
        $this->contract(['billing_cycle' => BillingCycle::Monthly->value, 'amount' => 30, 'quantity' => 2]);
        // Suspended → excluded.
        $this->contract([
            'billing_cycle' => BillingCycle::Monthly->value, 'amount' => 999, 'quantity' => 1,
            'status' => ServiceContractStatus::Suspended->value,
        ]);
        // One-Time → excluded (null months).
        $this->contract(['billing_cycle' => BillingCycle::OneTime->value, 'amount' => 500, 'quantity' => 1]);

        $insights = new ServiceContractInsights($this->tenant->id);

        $this->assertEqualsWithDelta(70.0, $insights->monthlyRecurringRevenue(), 0.001);
    }

    public function test_counts_and_renewal_window(): void
    {
        $this->contract(['status' => ServiceContractStatus::Active->value, 'next_due_date' => Carbon::today()->addDays(5)]);
        $this->contract(['status' => ServiceContractStatus::Active->value, 'next_due_date' => Carbon::today()->addDays(45)]);
        $this->contract(['status' => ServiceContractStatus::Suspended->value, 'next_due_date' => Carbon::today()->addDays(5)]);

        $insights = new ServiceContractInsights($this->tenant->id);

        $this->assertSame(2, $insights->activeCount());
        $this->assertSame(1, $insights->suspendedCount());
        // Only the Active one inside the 30-day window.
        $this->assertSame(1, $insights->renewalsDueWithin(30));
    }

    public function test_mrr_is_tenant_scoped(): void
    {
        $this->contract(['billing_cycle' => BillingCycle::Monthly->value, 'amount' => 50, 'quantity' => 1]);

        // Another tenant's contract must not leak into this tenant's MRR.
        $other = Company::create(['name' => 'Other', 'slug' => 'other-'.uniqid(), 'country_code' => 'GR']);
        $otherCustomer = Customer::create([
            'company_id' => $other->id, 'name' => 'Άλλος', 'afm' => '999999999',
            'address1' => 'Οδός 2', 'city' => 'Θεσ', 'postcode' => '54600', 'country' => 'GR',
        ]);
        ServiceContract::create([
            'company_id' => $other->id, 'customer_id' => $otherCustomer->id, 'description' => 'x',
            'billing_cycle' => BillingCycle::Monthly->value, 'amount' => 1000, 'quantity' => 1,
            'vat_percent' => 24, 'status' => ServiceContractStatus::Active->value,
            'next_due_date' => Carbon::today()->addDays(10),
        ]);

        $this->assertEqualsWithDelta(50.0, (new ServiceContractInsights($this->tenant->id))->monthlyRecurringRevenue(), 0.001);
    }
}
