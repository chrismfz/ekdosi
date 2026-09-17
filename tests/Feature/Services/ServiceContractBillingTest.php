<?php

namespace Tests\Feature\Services;

use App\Enums\ServiceContractStatus;
use App\Models\Company;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\InvoiceType;
use App\Models\ServiceContract;
use App\Services\ServiceContractBilling;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * #11 per-contract billing analytics. Verifies the money rules of
 * {@see ServiceContractBilling}: the billed count + revenue are built ONLY on
 * live, issued, non-credit sale renewals; credit notes reduce the net revenue;
 * unissued drafts are pipeline (not revenue); and the price-change timeline
 * comes from the audit log. All figures are explicitly company-scoped.
 */
class ServiceContractBillingTest extends TestCase
{
    use RefreshDatabase;

    private Company $tenant;

    private Customer $customer;

    private ServiceContract $contract;

    private InvoiceType $type;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-09-17 12:00:00'));

        $this->tenant = Company::create([
            'name' => 'SC', 'slug' => 'sc-'.uniqid(),
            'country_code' => 'GR', 'einvoice_provider' => 'gr-mydata', 'mydata_mode' => 'off',
        ]);
        $this->customer = Customer::create(['company_id' => $this->tenant->id, 'name' => 'ΠΕΛΑΤΗΣ ΑΕ']);
        $this->type = InvoiceType::create([
            'company_id' => $this->tenant->id, 'code' => 'ΤΠΥ', 'name' => 'ΤΠΥ', 'invcount' => 1,
        ]);
        $this->contract = ServiceContract::create([
            'company_id' => $this->tenant->id, 'customer_id' => $this->customer->id,
            'invoice_type_id' => $this->type->id, 'billing_cycle' => 'annual',
            'amount' => 100, 'vat_percent' => 24, 'status' => ServiceContractStatus::Active->value,
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function invoice(array $overrides): Invoice
    {
        static $n = 0;
        $n++;

        return Invoice::create(array_merge([
            'company_id' => $this->tenant->id,
            'customer_id' => $this->customer->id,
            'invoice_type_id' => $this->type->id,
            'service_contract_id' => $this->contract->id,
            'code' => $n, 'invcode' => 'ΤΠΥ'.$n,
            'issued_at' => '2026-03-01 10:00:00',
            'local_status' => 'active',
            'net_total' => 100, 'gross_total' => 124, 'payable_total' => 124,
        ], $overrides));
    }

    public function test_billed_count_and_revenue_cover_only_live_issued_sales(): void
    {
        $sale1 = $this->invoice(['issued_at' => '2026-03-01 10:00:00', 'net_total' => 100, 'gross_total' => 124]);
        $this->invoice(['issued_at' => '2026-06-01 10:00:00', 'net_total' => 200, 'gross_total' => 248]);
        // A cancelled renewal — never revenue.
        $this->invoice(['net_total' => 300, 'gross_total' => 372, 'local_status' => 'cancelled']);
        // An unissued draft — pipeline, not revenue.
        $this->invoice(['net_total' => 50, 'gross_total' => 62, 'local_status' => 'draft']);
        // A credit note against sale1 (positive net, correlated) — reduces both
        // net AND gross revenue (kept symmetric).
        $this->invoice(['net_total' => 50, 'gross_total' => 62, 'credited_invoice_id' => $sale1->id]);

        $billing = new ServiceContractBilling($this->contract->refresh());

        $this->assertSame(2, $billing->billedCount());               // sale1 + sale2 only
        $this->assertSame(250.0, $billing->netRevenue());            // 100 + 200 − 50
        $this->assertSame(310.0, $billing->grossBilled());           // 124 + 248 − 62
        $this->assertSame(1, $billing->pendingDraftCount());         // the draft
        $this->assertSame('01/03/2026', $billing->firstBilledAt()?->format('d/m/Y'));
        $this->assertSame('01/06/2026', $billing->lastBilledAt()?->format('d/m/Y'));
    }

    public function test_a_contract_without_invoices_reports_zeroes(): void
    {
        $billing = new ServiceContractBilling($this->contract);

        $this->assertSame(0, $billing->billedCount());
        $this->assertSame(0.0, $billing->netRevenue());
        $this->assertSame(0.0, $billing->grossBilled());
        $this->assertNull($billing->firstBilledAt());
        $this->assertNull($billing->lastBilledAt());
    }

    public function test_invoices_of_another_contract_are_not_counted(): void
    {
        $this->invoice(['net_total' => 100, 'gross_total' => 124]); // this contract

        $other = ServiceContract::create([
            'company_id' => $this->tenant->id, 'customer_id' => $this->customer->id,
            'invoice_type_id' => $this->type->id, 'billing_cycle' => 'annual',
            'amount' => 999, 'vat_percent' => 24, 'status' => ServiceContractStatus::Active->value,
        ]);
        $this->invoice(['service_contract_id' => $other->id, 'net_total' => 500, 'gross_total' => 620]);

        $billing = new ServiceContractBilling($this->contract->refresh());

        $this->assertSame(1, $billing->billedCount());   // NOT the other contract's invoice
        $this->assertSame(100.0, $billing->netRevenue());
    }

    public function test_price_history_reads_amount_changes_from_the_audit_log(): void
    {
        // The 'created' event logs amount=100; then two edits move the price.
        $this->contract->update(['amount' => 150]);
        $this->contract->update(['amount' => 180]);

        $history = (new ServiceContractBilling($this->contract->refresh()))->priceHistory();

        // Only real CHANGES: 100 → 150 and 150 → 180. The 'created' event (initial
        // price, no `old`) is not a change and is excluded.
        $this->assertCount(2, $history);
        $joined = implode(' | ', $history);
        $this->assertStringContainsString('150', $joined);
        $this->assertStringContainsString('180', $joined);
    }

    public function test_price_history_is_empty_for_a_never_edited_contract(): void
    {
        // A contract created but whose price was never changed has no timeline.
        $history = (new ServiceContractBilling($this->contract->refresh()))->priceHistory();

        $this->assertSame([], $history);
    }
}
