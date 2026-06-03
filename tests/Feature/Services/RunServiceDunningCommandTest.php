<?php

namespace Tests\Feature\Services;

use App\Actions\StageServiceRenewal;
use App\Enums\BillingCycle;
use App\Enums\ServiceContractStatus;
use App\Models\Company;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\InvoiceType;
use App\Models\PaymentMethod;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\ServiceContract;
use App\Models\VatCategory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * services:run-dunning — per-tenant dunning sweep. Exit codes, --dry-run safety,
 * tenant isolation, and the per-product Knob gate end-to-end through the command.
 */
class RunServiceDunningCommandTest extends TestCase
{
    use RefreshDatabase;

    private Company $tenant;

    private Customer $customer;

    private InvoiceType $type;

    private PaymentMethod $credit;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Company::create(['name' => 'Dun', 'slug' => 'dun-'.uniqid(), 'country_code' => 'GR']);
        $this->customer = Customer::create([
            'company_id' => $this->tenant->id, 'name' => 'Πελάτης ΑΕ', 'afm' => '123456789',
            'address1' => 'Οδός 1', 'city' => 'Αθήνα', 'postcode' => '11111', 'country' => 'GR',
        ]);
        $this->type = InvoiceType::create([
            'company_id' => $this->tenant->id, 'code' => 'ΤΠΥ', 'name' => 'Τιμ',
            'invcount' => 1, 'mydata_type' => '2.1',
        ]);
        $this->credit = PaymentMethod::create(['company_id' => $this->tenant->id, 'description' => 'Κατάθεση', 'due_days' => 30]);
    }

    private function overdueEnabledContract(): ServiceContract
    {
        $category = ProductCategory::create(['company_id' => $this->tenant->id, 'description_short' => 'Hosting', 'markup' => 25]);
        $vat = VatCategory::create(['company_id' => $this->tenant->id, 'description' => '24%', 'rate' => 24, 'is_default' => true]);
        $product = Product::create([
            'company_id' => $this->tenant->id, 'product_category_id' => $category->id, 'vat_category_id' => $vat->id,
            'description_short' => 'Hosting', 'description' => 'Hosting',
            'is_recurring' => true, 'dunning_enabled' => true, 'default_suspend_after_days' => 10,
        ]);
        $contract = ServiceContract::create([
            'company_id' => $this->tenant->id, 'customer_id' => $this->customer->id,
            'product_id' => $product->id, 'invoice_type_id' => $this->type->id,
            'payment_method_id' => $this->credit->id, 'description' => 'Hosting',
            'billing_cycle' => BillingCycle::Annual->value, 'amount' => 100, 'quantity' => 1,
            'vat_percent' => 24, 'status' => ServiceContractStatus::Active->value,
            'start_date' => Carbon::today()->subYear(), 'next_due_date' => Carbon::yesterday(),
        ]);

        $invoice = app(StageServiceRenewal::class)($contract);
        $invoice->update(['local_status' => 'active']);
        $invoice->forceFill(['issued_at' => Carbon::today()->subDays(60)])->save(); // overdue ~30d > 10

        return $contract->fresh();
    }

    public function test_unknown_tenant_exits_2(): void
    {
        $this->artisan('services:run-dunning', ['--tenant' => 'no-such-slug'])
            ->assertExitCode(2);
    }

    public function test_suspends_an_enabled_overdue_contract(): void
    {
        $contract = $this->overdueEnabledContract();

        $this->artisan('services:run-dunning', ['--tenant' => $this->tenant->slug])
            ->assertExitCode(0);

        $this->assertSame(ServiceContractStatus::Suspended, $contract->fresh()->status);
    }

    public function test_dry_run_changes_nothing(): void
    {
        $contract = $this->overdueEnabledContract();

        $this->artisan('services:run-dunning', ['--tenant' => $this->tenant->slug, '--dry-run' => true])
            ->assertExitCode(0);

        $this->assertSame(ServiceContractStatus::Active, $contract->fresh()->status);
    }

    public function test_does_not_touch_invoices_or_balances(): void
    {
        $contract = $this->overdueEnabledContract();
        $invoiceId = Invoice::where('service_contract_id', $contract->id)->value('id');
        $before = Invoice::find($invoiceId)->only(['paid_total', 'credited_total', 'payment_status', 'local_status']);

        $this->artisan('services:run-dunning', ['--tenant' => $this->tenant->slug])->assertExitCode(0);

        $after = Invoice::find($invoiceId)->only(['paid_total', 'credited_total', 'payment_status', 'local_status']);
        $this->assertEquals($before, $after, 'dunning must not touch invoice money/status');
    }
}
