<?php

namespace Tests\Feature\Services;

use App\Actions\StageServiceRenewal;
use App\Enums\BillingCycle;
use App\Enums\PaymentStatus;
use App\Enums\ServiceContractStatus;
use App\Models\Company;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\InvoiceType;
use App\Models\Payment;
use App\Models\PaymentMethod;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\ServiceContract;
use App\Models\VatCategory;
use App\Services\Services\ServiceDunning;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * ServiceDunning: decides + applies suspend/terminate/unsuspend off the overdue
 * signal of a contract's renewal invoices, gated by the per-product «Knob»
 * (default OFF). DESTRUCTIVE feature → these tests assert it NEVER acts unless
 * genuinely in arrears AND opted in, and that --dry-run changes nothing.
 */
class ServiceDunningTest extends TestCase
{
    use RefreshDatabase;

    private Company $tenant;

    private Customer $customer;

    private InvoiceType $type;

    private PaymentMethod $credit;

    private ProductCategory $category;

    private VatCategory $vat;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Company::create(['name' => 'Dun', 'slug' => 'dun-'.uniqid(), 'country_code' => 'GR']);
        $this->category = ProductCategory::create(['company_id' => $this->tenant->id, 'description_short' => 'Hosting', 'markup' => 25]);
        $this->vat = VatCategory::create(['company_id' => $this->tenant->id, 'description' => '24%', 'rate' => 24, 'is_default' => true]);
        $this->customer = Customer::create([
            'company_id' => $this->tenant->id, 'name' => 'Πελάτης ΑΕ', 'afm' => '123456789',
            'address1' => 'Οδός 1', 'city' => 'Αθήνα', 'postcode' => '11111', 'country' => 'GR',
        ]);
        $this->type = InvoiceType::create([
            'company_id' => $this->tenant->id, 'code' => 'ΤΠΥ', 'name' => 'Τιμολόγιο Παροχής',
            'invcount' => 1, 'mydata_type' => '2.1',
        ]);
        // Credit-term (due_days>0) so a staged renewal is a real receivable that
        // can go overdue — that's the dunning signal.
        $this->credit = PaymentMethod::create([
            'company_id' => $this->tenant->id, 'description' => 'Κατάθεση', 'due_days' => 30,
        ]);
    }

    private function product(array $overrides = []): Product
    {
        return Product::create(array_merge([
            'company_id' => $this->tenant->id,
            'product_category_id' => $this->category->id,
            'vat_category_id' => $this->vat->id,
            'description_short' => 'Hosting',
            'description' => 'Hosting',
            'is_recurring' => true,
            'dunning_enabled' => false,
            'default_suspend_after_days' => 10,
            'default_terminate_after_days' => null, // suspend-only by default
        ], $overrides));
    }

    private function contract(array $overrides = []): ServiceContract
    {
        return ServiceContract::create(array_merge([
            'company_id' => $this->tenant->id,
            'customer_id' => $this->customer->id,
            'product_id' => null,
            'invoice_type_id' => $this->type->id,
            'payment_method_id' => $this->credit->id,
            'description' => 'Hosting Personal5',
            'billing_cycle' => BillingCycle::Annual->value,
            'amount' => 100,
            'vat_percent' => 24,
            'status' => ServiceContractStatus::Active->value,
            'start_date' => Carbon::today()->subYear(),
            'next_due_date' => Carbon::yesterday(),
        ], $overrides));
    }

    /**
     * Stage a renewal, issue it (draft→active), then back-date issued_at so the
     * credit-term renewal is overdue by ~$daysOverdue days. Leaves it unpaid →
     * Invoice::isOverdue() is true and the cached payment_status is Unpaid.
     */
    private function overdueRenewal(ServiceContract $contract, int $daysOverdue): Invoice
    {
        $invoice = app(StageServiceRenewal::class)($contract);
        // Issue it (the observer advances the contract cursor — irrelevant to
        // the invoice's own overdue signal). Then back-date so due < today.
        $invoice->update(['local_status' => 'active']);
        // issued_at + due_days(30) must be $daysOverdue in the past.
        $invoice->forceFill([
            'issued_at' => Carbon::today()->subDays(30 + $daysOverdue),
        ])->save(); // observer recompute refreshes payment_status cache

        $fresh = $invoice->fresh();
        // Sanity: the renewal really is overdue + unpaid.
        $this->assertTrue($fresh->isOverdue(), 'fixture renewal must be overdue');
        $this->assertSame(PaymentStatus::Unpaid->value, (string) $fresh->payment_status);

        return $fresh;
    }

    private function dunning(): ServiceDunning
    {
        return app(ServiceDunning::class);
    }

    public function test_disabled_knob_is_a_no_op_even_when_very_overdue(): void
    {
        $product = $this->product(['dunning_enabled' => false]);
        $contract = $this->contract(['product_id' => $product->id, 'dunning_enabled' => null]);
        $this->overdueRenewal($contract, 90);

        $action = $this->dunning()->evaluate($contract->fresh(), Carbon::today());

        $this->assertNull($action);
        $this->assertSame(ServiceContractStatus::Active, $contract->fresh()->status);
    }

    public function test_suspends_when_overdue_past_suspend_with_terminate_null(): void
    {
        $product = $this->product([
            'dunning_enabled' => true,
            'default_suspend_after_days' => 10,
            'default_terminate_after_days' => null, // suspend-only
        ]);
        $contract = $this->contract(['product_id' => $product->id]);
        $this->overdueRenewal($contract, 20); // 20 > 10

        $action = $this->dunning()->evaluate($contract->fresh(), Carbon::today());

        $this->assertSame('suspended', $action);
        $fresh = $contract->fresh();
        $this->assertSame(ServiceContractStatus::Suspended, $fresh->status);
        $this->assertNotNull($fresh->suspended_at);
        $this->assertNull($fresh->terminated_at, 'must NOT terminate when terminate threshold is null');
    }

    public function test_terminates_when_overdue_past_terminate_and_cancels_drafts(): void
    {
        $product = $this->product([
            'dunning_enabled' => true,
            'default_suspend_after_days' => 10,
            'default_terminate_after_days' => 30,
        ]);
        $contract = $this->contract(['product_id' => $product->id]);
        $this->overdueRenewal($contract, 45); // 45 > 30

        // Stage a SECOND, still-unissued draft renewal → it must be cancelled by
        // the terminate cascade. Back-date the cursor so a new draft stages.
        $contract->fresh()->update(['next_due_date' => Carbon::today()->subDays(2)->toDateString()]);
        $draft = app(StageServiceRenewal::class)($contract->fresh());
        $this->assertNotNull($draft);
        $this->assertSame('draft', $draft->local_status);

        $action = $this->dunning()->evaluate($contract->fresh(), Carbon::today());

        $this->assertSame('terminated', $action);
        $fresh = $contract->fresh();
        $this->assertSame(ServiceContractStatus::Terminated, $fresh->status);
        $this->assertNotNull($fresh->terminated_at);
        $this->assertNull($fresh->next_due_date, 'terminate clears the billing cursor');
        // The unissued draft was cancelled by the cascade.
        $this->assertSame('cancelled', $draft->fresh()->local_status);
    }

    public function test_suspended_contract_reactivates_when_renewal_paid(): void
    {
        $product = $this->product(['dunning_enabled' => true, 'default_suspend_after_days' => 10]);
        $contract = $this->contract(['product_id' => $product->id]);
        $renewal = $this->overdueRenewal($contract, 20);

        // First sweep suspends it.
        $this->assertSame('suspended', $this->dunning()->evaluate($contract->fresh(), Carbon::today()));
        $this->assertSame(ServiceContractStatus::Suspended, $contract->fresh()->status);

        // Customer pays the renewal in full → no overdue invoice remains.
        Payment::create([
            'company_id' => $this->tenant->id,
            'customer_id' => $this->customer->id,
            'invoice_id' => $renewal->id,
            'kind' => 'payment',
            'amount' => $renewal->gross_total,
            'pay_date' => Carbon::today(),
            'payment_method_id' => $this->credit->id,
        ]);
        $this->assertFalse($renewal->fresh()->isOverdue(), 'paid renewal is no longer overdue');

        // Next sweep reactivates the suspended contract.
        $action = $this->dunning()->evaluate($contract->fresh(), Carbon::today());

        $this->assertSame('unsuspended', $action);
        $fresh = $contract->fresh();
        $this->assertSame(ServiceContractStatus::Active, $fresh->status);
        $this->assertNull($fresh->suspended_at);
    }

    public function test_contract_override_false_beats_an_enabled_product(): void
    {
        $product = $this->product(['dunning_enabled' => true, 'default_suspend_after_days' => 10]);
        $contract = $this->contract(['product_id' => $product->id, 'dunning_enabled' => false]);
        $this->overdueRenewal($contract, 30);

        $this->assertNull($this->dunning()->evaluate($contract->fresh(), Carbon::today()));
        $this->assertSame(ServiceContractStatus::Active, $contract->fresh()->status);
    }

    public function test_contract_override_true_beats_a_disabled_product(): void
    {
        $product = $this->product(['dunning_enabled' => false, 'default_suspend_after_days' => 10]);
        $contract = $this->contract(['product_id' => $product->id, 'dunning_enabled' => true]);
        $this->overdueRenewal($contract, 20);

        $this->assertSame('suspended', $this->dunning()->evaluate($contract->fresh(), Carbon::today()));
        $this->assertSame(ServiceContractStatus::Suspended, $contract->fresh()->status);
    }

    public function test_dry_run_changes_nothing(): void
    {
        $product = $this->product(['dunning_enabled' => true, 'default_suspend_after_days' => 10, 'default_terminate_after_days' => 30]);
        $contract = $this->contract(['product_id' => $product->id]);
        $this->overdueRenewal($contract, 45); // would terminate

        $would = $this->dunning()->wouldDo($contract->fresh(), Carbon::today());

        $this->assertSame('terminated', $would, 'wouldDo reports the decision');
        // …but persisted nothing.
        $fresh = $contract->fresh();
        $this->assertSame(ServiceContractStatus::Active, $fresh->status);
        $this->assertNull($fresh->terminated_at);
        $this->assertNotNull($fresh->next_due_date);
    }

    public function test_manually_suspended_contract_without_renewals_is_not_auto_reactivated(): void
    {
        // A Suspended contract with NO issued renewal (e.g. suspended by hand,
        // no billing history). dunning enabled + overdueDays trivially 0 — must
        // NOT auto-unsuspend (would silently undo the operator's manual action).
        $product = $this->product(['dunning_enabled' => true, 'default_suspend_after_days' => 10]);
        $contract = $this->contract([
            'product_id' => $product->id,
            'status' => ServiceContractStatus::Suspended->value,
        ]);

        $this->assertNull($this->dunning()->evaluate($contract->fresh(), Carbon::today()));
        $this->assertSame(ServiceContractStatus::Suspended, $contract->fresh()->status);
    }

    public function test_current_paid_contract_is_never_suspended(): void
    {
        // Enabled product + a renewal that is NOT overdue (issued recently,
        // within credit term) → overdueDays = 0 → no action.
        $product = $this->product(['dunning_enabled' => true, 'default_suspend_after_days' => 10]);
        $contract = $this->contract(['product_id' => $product->id]);

        $invoice = app(StageServiceRenewal::class)($contract);
        $invoice->update(['local_status' => 'active']);
        // issued today → due in 30 days → NOT overdue.
        $invoice->forceFill(['issued_at' => Carbon::today()])->save();
        $this->assertFalse($invoice->fresh()->isOverdue());

        $this->assertNull($this->dunning()->evaluate($contract->fresh(), Carbon::today()));
        $this->assertSame(ServiceContractStatus::Active, $contract->fresh()->status);
    }
}
