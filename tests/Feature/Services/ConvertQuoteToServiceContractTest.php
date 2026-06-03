<?php

namespace Tests\Feature\Services;

use App\Actions\ConvertQuoteToServiceContract;
use App\Actions\StageServiceRenewal;
use App\Enums\BillingCycle;
use App\Enums\QuoteStatus;
use App\Enums\ServiceContractStatus;
use App\Models\Company;
use App\Models\Customer;
use App\Models\InvoiceLine;
use App\Models\InvoiceType;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\Quote;
use App\Models\QuoteLine;
use App\Models\ServiceContract;
use App\Models\VatCategory;
use App\Services\QuoteNumberer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\TestCase;

/**
 * «Μετατροπή σε Υπηρεσία»: an accepted quote → a recurring service contract (for
 * the FUTURE renewals) + a first DRAFT invoice carrying ALL the quote's lines
 * (the one-time «Setup Fee» + the recurring «IT Support» first period).
 */
class ConvertQuoteToServiceContractTest extends TestCase
{
    use RefreshDatabase;

    private Company $tenant;

    private Customer $customer;

    private InvoiceType $type;

    private Product $recurring;   // IT Support (recurring)

    private Product $oneTime;     // Setup Fee (one-time)

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = Company::create(['name' => 't', 'slug' => 't-'.uniqid(), 'country_code' => 'GR']);
        $this->customer = Customer::create(['company_id' => $this->tenant->id, 'name' => 'Πελάτης ΑΕ', 'afm' => '123456789']);
        $this->type = InvoiceType::create(['company_id' => $this->tenant->id, 'name' => 'ΤΠΥ', 'code' => 'ΤΠΥ', 'invcount' => 1, 'show_on_menu' => true, 'mydata_type' => '2.1']);
        $cat = ProductCategory::create(['company_id' => $this->tenant->id, 'description_short' => 'Υπηρεσίες', 'markup' => 0]);
        $vat = VatCategory::create(['company_id' => $this->tenant->id, 'description' => '24%', 'rate' => 24, 'is_default' => true]);
        $this->recurring = Product::create([
            'company_id' => $this->tenant->id, 'product_category_id' => $cat->id, 'vat_category_id' => $vat->id,
            'description_short' => 'IT Support', 'is_recurring' => true, 'default_billing_cycle' => BillingCycle::Annual->value,
        ]);
        $this->oneTime = Product::create([
            'company_id' => $this->tenant->id, 'product_category_id' => $cat->id, 'vat_category_id' => $vat->id,
            'description_short' => 'Setup Fee', 'is_recurring' => false,
        ]);
    }

    private function acceptedQuote(): Quote
    {
        $quote = DB::transaction(fn () => Quote::create([
            'company_id' => $this->tenant->id, 'customer_id' => $this->customer->id,
            'code' => app(QuoteNumberer::class)->allocate($this->tenant),
            'issued_at' => now(), 'status' => QuoteStatus::Accepted,
            'company_name' => 'Πελάτης ΑΕ', 'vat_no' => '123456789',
        ]));
        // Setup Fee (one-time) €100 + IT Support (recurring) €1200.
        QuoteLine::create(['quote_id' => $quote->id, 'company_id' => $this->tenant->id, 'product_id' => $this->oneTime->id, 'product_descr' => 'Setup Fee', 'qty' => 1, 'price_per_item' => 100, 'vat_percent' => 24]);
        QuoteLine::create(['quote_id' => $quote->id, 'company_id' => $this->tenant->id, 'product_id' => $this->recurring->id, 'product_descr' => 'IT Support', 'qty' => 1, 'price_per_item' => 1200, 'vat_percent' => 24]);

        return $quote->fresh('lines');
    }

    public function test_creates_contract_plus_first_invoice_with_all_lines(): void
    {
        $quote = $this->acceptedQuote();

        $contract = app(ConvertQuoteToServiceContract::class)(
            $quote, $this->type, BillingCycle::Annual, 1200.0
        );

        // Contract = the recurring part only.
        $this->assertSame(ServiceContractStatus::Active, $contract->status);
        $this->assertSame(BillingCycle::Annual, $contract->billing_cycle);
        $this->assertSame(1200.0, (float) $contract->amount);
        $this->assertSame($this->recurring->id, $contract->product_id);
        $this->assertSame(Carbon::today()->toDateString(), $contract->next_due_date->toDateString());

        // First invoice = the FULL quote (both lines), linked + draft.
        $invoice = $contract->invoices()->first();
        $this->assertNotNull($invoice);
        $this->assertSame('draft', $invoice->local_status);
        $this->assertSame(2, InvoiceLine::where('invoice_id', $invoice->id)->count());
        $this->assertEqualsWithDelta(1300.0, (float) $invoice->net_total, 0.01); // 100 + 1200

        // Provenance both ways.
        $this->assertSame($contract->id, $quote->fresh()->converted_service_contract_id);
        $this->assertSame($invoice->id, $quote->fresh()->converted_invoice_id);
    }

    public function test_issuing_first_invoice_advances_cursor_and_future_renewal_is_recurring_only(): void
    {
        $quote = $this->acceptedQuote();
        $contract = app(ConvertQuoteToServiceContract::class)($quote, $this->type, BillingCycle::Annual, 1200.0);
        $invoice = $contract->invoices()->first();

        // Issue the first invoice → cursor advances one year (period 1 → period 2).
        $invoice->update(['local_status' => 'active']);
        $contract->refresh();
        $this->assertSame(Carbon::today()->addYear()->toDateString(), $contract->next_due_date->toDateString());

        // Back-date the cursor so the next renewal is due, then stage it.
        $contract->forceFill(['next_due_date' => Carbon::yesterday()->toDateString()])->save();
        $renewal = app(StageServiceRenewal::class)($contract->fresh());

        // The future renewal carries ONLY the recurring line (no setup) — net 1200.
        $this->assertSame(1, InvoiceLine::where('invoice_id', $renewal->id)->count());
        $this->assertEqualsWithDelta(1200.0, (float) $renewal->net_total, 0.01);
    }

    public function test_quote_with_only_recurring_product(): void
    {
        $quote = DB::transaction(fn () => Quote::create([
            'company_id' => $this->tenant->id, 'customer_id' => $this->customer->id,
            'code' => app(QuoteNumberer::class)->allocate($this->tenant),
            'issued_at' => now(), 'status' => QuoteStatus::Accepted,
        ]));
        QuoteLine::create(['quote_id' => $quote->id, 'company_id' => $this->tenant->id, 'product_id' => $this->recurring->id, 'product_descr' => 'IT Support', 'qty' => 1, 'price_per_item' => 1200, 'vat_percent' => 24]);

        $contract = app(ConvertQuoteToServiceContract::class)($quote->fresh('lines'), $this->type, BillingCycle::Annual, 1200.0);
        $invoice = $contract->invoices()->first();

        $this->assertSame(1, InvoiceLine::where('invoice_id', $invoice->id)->count());
        $this->assertEqualsWithDelta(1200.0, (float) $invoice->net_total, 0.01);
    }

    public function test_refuses_a_already_converted_quote(): void
    {
        $quote = $this->acceptedQuote();
        app(ConvertQuoteToServiceContract::class)($quote, $this->type, BillingCycle::Annual, 1200.0);

        $this->expectException(RuntimeException::class);
        app(ConvertQuoteToServiceContract::class)($quote->fresh('lines'), $this->type, BillingCycle::Annual, 1200.0);
    }

    public function test_refuses_a_non_accepted_quote(): void
    {
        $quote = $this->acceptedQuote();
        $quote->update(['status' => QuoteStatus::Sent]); // not accepted

        $this->expectException(RuntimeException::class);
        app(ConvertQuoteToServiceContract::class)($quote->fresh('lines'), $this->type, BillingCycle::Annual, 1200.0);
    }

    public function test_refuses_one_time_cycle_and_zero_amount(): void
    {
        $quote = $this->acceptedQuote();

        try {
            app(ConvertQuoteToServiceContract::class)($quote, $this->type, BillingCycle::OneTime, 1200.0);
            $this->fail('OneTime cycle must be refused.');
        } catch (RuntimeException) {
        }

        $this->assertSame(0, ServiceContract::where('company_id', $this->tenant->id)->count());
    }
}
