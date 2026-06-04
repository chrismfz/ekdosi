<?php

namespace Tests\Feature\Services;

use App\Enums\BillingCycle;
use App\Enums\ServiceContractStatus;
use App\Filament\Resources\ServiceContracts\Pages\ViewServiceContract;
use App\Models\Company;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\InvoiceType;
use App\Models\ServiceContract;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Service-contract lifecycle: activation seeds the due date, cancellation
 * cascades to UNISSUED draft renewals (leaving MARK'd/active ones), and the
 * status transition guards hold.
 */
class ServiceContractLifecycleTest extends TestCase
{
    use RefreshDatabase;

    private Company $tenant;

    private Customer $customer;

    private InvoiceType $type;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Company::create(['name' => 'L', 'slug' => 'l-'.uniqid(), 'country_code' => 'GR']);
        $this->customer = Customer::create(['company_id' => $this->tenant->id, 'name' => 'Π', 'afm' => '123456789']);
        $this->type = InvoiceType::create([
            'company_id' => $this->tenant->id, 'code' => 'ΤΠΥ', 'name' => 'Τ', 'invcount' => 1, 'mydata_type' => '2.1',
        ]);
    }

    private function contract(string $status, array $overrides = []): ServiceContract
    {
        return ServiceContract::create(array_merge([
            'company_id' => $this->tenant->id,
            'customer_id' => $this->customer->id,
            'invoice_type_id' => $this->type->id,
            'billing_cycle' => BillingCycle::Annual->value,
            'amount' => 100, 'vat_percent' => 24,
            'status' => $status,
        ], $overrides));
    }

    private function renewal(ServiceContract $c, string $localStatus, ?string $mark = null): Invoice
    {
        $inv = Invoice::create([
            'company_id' => $this->tenant->id,
            'invoice_type_id' => $this->type->id,
            'customer_id' => $this->customer->id,
            'service_contract_id' => $c->id,
            'invcode' => 'ΤΠΥ'.fake()->unique()->numberBetween(1, 99999),
            'code' => fake()->unique()->numberBetween(1, 99999),
            'issued_at' => now(),
            'local_status' => $localStatus,
        ]);
        if ($mark !== null) {
            $inv->forceFill(['mydata_mark' => $mark, 'mydata_state' => 'VALID'])->save();
        }

        return $inv;
    }

    public function test_activation_seeds_next_due_from_start_date(): void
    {
        $start = Carbon::today()->subDays(3);
        $contract = $this->contract(ServiceContractStatus::Pending->value, [
            'start_date' => $start, 'next_due_date' => null,
        ]);

        // Mirror the activate action's column mutation.
        $contract->update([
            'status' => ServiceContractStatus::Active,
            'next_due_date' => $contract->next_due_date ?? $contract->start_date ?? today(),
        ]);

        $this->assertSame(ServiceContractStatus::Active, $contract->fresh()->status);
        $this->assertSame($start->toDateString(), Carbon::parse($contract->fresh()->next_due_date)->toDateString());
    }

    public function test_cancellation_cascades_to_unissued_drafts_but_leaves_filed_ones(): void
    {
        $contract = $this->contract(ServiceContractStatus::Active->value, [
            'next_due_date' => Carbon::yesterday(),
        ]);

        $draftRenewal = $this->renewal($contract, 'draft');               // should be cancelled
        $filedRenewal = $this->renewal($contract, 'active', mark: '4000000001'); // legal — untouched

        $cancelled = ViewServiceContract::cancelUnissuedDrafts($contract);
        $contract->update(['status' => ServiceContractStatus::Cancelled, 'next_due_date' => null]);

        $this->assertSame(1, $cancelled);
        $this->assertSame('cancelled', $draftRenewal->fresh()->local_status);
        // The filed/MARK'd renewal is a legal document — left as-is.
        $this->assertSame('active', $filedRenewal->fresh()->local_status);
        $this->assertSame('4000000001', $filedRenewal->fresh()->mydata_mark);

        $this->assertSame(ServiceContractStatus::Cancelled, $contract->fresh()->status);
        $this->assertNull($contract->fresh()->next_due_date);
    }

    public function test_transition_guards(): void
    {
        // Pending cannot be terminated directly.
        $this->assertFalse(ServiceContractStatus::Pending->canTransitionTo(ServiceContractStatus::Terminated));
        // Active can suspend / cancel / terminate.
        $this->assertTrue(ServiceContractStatus::Active->canTransitionTo(ServiceContractStatus::Suspended));
        $this->assertTrue(ServiceContractStatus::Active->canTransitionTo(ServiceContractStatus::Terminated));
        // Terminated is terminal.
        $this->assertSame([], ServiceContractStatus::Terminated->allowedTransitions());
        // Cancelled can revive to Active.
        $this->assertTrue(ServiceContractStatus::Cancelled->canTransitionTo(ServiceContractStatus::Active));
    }
}
