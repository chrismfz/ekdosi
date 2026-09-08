<?php

namespace Tests\Feature\Assistant;

use App\Models\Company;
use App\Models\Customer;
use App\Models\Expense;
use App\Models\Invoice;
use App\Models\InvoiceLine;
use App\Models\InvoiceType;
use App\Models\PaymentMethod;
use App\Models\PendingWhmcsInvoice;
use App\Models\User;
use App\Services\Assistant\Tools\IncomeVsExpenseTool;
use App\Services\Assistant\Tools\TopProductsTool;
use App\Services\Assistant\Tools\WhmcsInboxTool;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Gate;
use Tests\TestCase;

/**
 * Phase 2c (α): the new read tools (income_vs_expense, top_products, whmcs_inbox).
 * Each is dual-surface (an MCP adapter is enforced by McpAssistantParityTest); here
 * we exercise the in-app tool logic.
 */
class AssistantReadToolsTest extends TestCase
{
    use RefreshDatabase;

    private Company $tenant;

    private InvoiceType $type;

    private PaymentMethod $method;

    protected function setUp(): void
    {
        parent::setUp();
        Gate::before(fn () => true);
        $this->tenant = Company::create([
            'name' => 'Read OE', 'slug' => 'read-'.uniqid(), 'country_code' => 'GR',
            'einvoice_provider' => 'gr-mydata', 'mydata_mode' => 'off',
        ]);
        $this->actingAs(User::create(['name' => 'Op', 'email' => 'op-'.uniqid().'@t.local', 'password' => bcrypt('x')]));
        Filament::setTenant($this->tenant);

        $this->method = PaymentMethod::create(['company_id' => $this->tenant->id, 'description' => 'Μετρητά', 'due_days' => 0]);
        $this->type = InvoiceType::create(['company_id' => $this->tenant->id, 'code' => 'TPY', 'name' => 'ΤΠΥ', 'invcount' => 1, 'mydata_type' => '2.1']);
    }

    private function invoice(float $net, float $gross): Invoice
    {
        $c = Customer::create(['company_id' => $this->tenant->id, 'name' => 'Πελ '.uniqid(), 'afm' => (string) random_int(100000000, 999999999)]);

        return Invoice::create([
            'company_id' => $this->tenant->id, 'invcode' => 'I'.uniqid(), 'code' => random_int(1, 99999),
            'invoice_type_id' => $this->type->id, 'payment_method_id' => $this->method->id, 'customer_id' => $c->id,
            'issued_at' => now(), 'local_status' => 'active',
            'net_total' => $net, 'gross_total' => $gross, 'header_discount_percent' => 0,
        ]);
    }

    public function test_income_vs_expense_sums_both_sides_and_the_vat_balance(): void
    {
        $this->invoice(1000, 1240);                 // income: net 1000, vat 240
        Expense::create([
            'company_id' => $this->tenant->id, 'issue_date' => now()->toDateString(),
            'supplier_afm' => '123456789', 'supplier_name' => 'Προμηθευτής',
            'net_total' => 400, 'vat_total' => 96, 'gross_total' => 496, 'currency' => 'EUR',
        ]);

        // Explicit wide window: a DATE column on sqlite stores the cast's
        // «Y-m-d 00:00:00», so a date-only upper bound == today would lexically
        // drop today's expense (a MariaDB DATE truncates, so prod is unaffected).
        $res = (new IncomeVsExpenseTool)->run($this->tenant, [
            'from' => now()->subYear()->toDateString(),
            'to' => now()->addYear()->toDateString(),
        ]);

        $this->assertEqualsWithDelta(1000.0, $res['income']['net'], 0.01);
        $this->assertEqualsWithDelta(240.0, $res['income']['vat'], 0.01);
        $this->assertEqualsWithDelta(400.0, $res['expense']['net'], 0.01);
        $this->assertEqualsWithDelta(96.0, $res['expense']['vat'], 0.01);
        $this->assertEqualsWithDelta(144.0, $res['vat_balance'], 0.01); // 240 − 96, «προς απόδοση»
        $this->assertSame('προς απόδοση', $res['vat_balance_note']);
    }

    public function test_top_products_ranks_by_frequency(): void
    {
        // «Hosting» sold on two invoices, «SSL» on one → hosting first.
        foreach ([100.0, 100.0] as $net) {
            $inv = $this->invoice($net, round($net * 1.24, 2));
            InvoiceLine::create([
                'company_id' => $this->tenant->id, 'invoice_id' => $inv->id,
                'qty' => 1, 'price_per_item' => $net, 'vat_percent' => 24,
                'net_price' => $net, 'gross_price' => round($net * 1.24, 2), 'product_descr' => 'Hosting',
            ]);
        }
        $inv = $this->invoice(50, 62);
        InvoiceLine::create([
            'company_id' => $this->tenant->id, 'invoice_id' => $inv->id,
            'qty' => 1, 'price_per_item' => 50, 'vat_percent' => 24,
            'net_price' => 50, 'gross_price' => 62, 'product_descr' => 'SSL',
        ]);

        $res = (new TopProductsTool)->run($this->tenant, ['limit' => 10]);

        $this->assertSame(2, $res['count']);
        $this->assertSame('Hosting', $res['products'][0]['label']);
        $this->assertSame(2, $res['products'][0]['times']);
        $this->assertEqualsWithDelta(200.0, $res['products'][0]['net'], 0.01);
        $this->assertSame('SSL', $res['products'][1]['label']);
    }

    public function test_top_products_limit_is_clamped(): void
    {
        $res = (new TopProductsTool)->run($this->tenant, ['limit' => 9999]);
        $this->assertSame(50, $res['limit']); // MAX_LIMIT
    }

    public function test_whmcs_inbox_counts_open_by_status(): void
    {
        $make = fn (string $status) => PendingWhmcsInvoice::create([
            'company_id' => $this->tenant->id, 'whmcs_invoice_id' => random_int(1, 999999),
            'payload' => [], 'match_reason' => 'test', 'status' => $status,
        ]);
        $make(PendingWhmcsInvoice::STATUS_PENDING_REVIEW);
        $make(PendingWhmcsInvoice::STATUS_PENDING_REVIEW);
        $make(PendingWhmcsInvoice::STATUS_HELD);
        $make(PendingWhmcsInvoice::STATUS_FILED);      // terminal — not «open»
        $make(PendingWhmcsInvoice::STATUS_REJECTED);   // terminal — not «open»

        $res = (new WhmcsInboxTool)->run($this->tenant, []);

        $this->assertSame(2, $res['pending_review']); // == the «Εισερχόμενα» nav badge
        $this->assertSame(1, $res['held']);
        $this->assertArrayNotHasKey('open_total', $res); // no total that no panel surface shows
    }

    public function test_top_products_caps_a_huge_window(): void
    {
        $res = (new TopProductsTool)->run($this->tenant, ['from' => '2000-01-01', 'to' => now()->toDateString()]);
        $this->assertTrue($res['window_capped']);
        // ~2 years back, not 25 — bounds the in-memory line load.
        $this->assertTrue(Carbon::parse($res['from'])->gt(now()->subYears(3)));
    }
}
