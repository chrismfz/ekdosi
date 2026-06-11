<?php

namespace Tests\Feature\Invoice;

use App\Actions\IssueCreditNote;
use App\Models\Company;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\InvoiceLine;
use App\Models\InvoiceType;
use App\Models\PaymentMethod;
use App\Services\Dashboard\DashboardMetrics;
use App\Services\InvoiceBalance;
use App\Services\RecomputeInvoiceTotals;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The «withholding/fees count toward owed» change (day-2 rung 2a): a service
 * invoice with 20% παρακράτηση is collected NET of the withholding, so the
 * receivable (per-invoice balance AND the dashboard) must show the reduced
 * payable — while gross_total stays net+VAT (revenue/turnover).
 */
class WithholdingReceivableTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function withholding_reduces_the_receivable_per_invoice_and_on_the_dashboard(): void
    {
        $tenant = Company::create([
            'name' => 'WH', 'slug' => 'wh-'.uniqid(), 'country_code' => 'GR',
            'einvoice_provider' => 'gr-mydata', 'mydata_mode' => 'off', 'afm' => '800561849',
        ]);
        $type = InvoiceType::create(['company_id' => $tenant->id, 'code' => 'TPY', 'name' => 'ΤΠΥ', 'invcount' => 1]);
        $pm = PaymentMethod::create(['company_id' => $tenant->id, 'description' => '30 ημέρες', 'due_days' => 30]);
        $customer = Customer::create(['company_id' => $tenant->id, 'name' => 'Acme']);

        $inv = Invoice::create([
            'company_id' => $tenant->id, 'invoice_type_id' => $type->id, 'customer_id' => $customer->id,
            'payment_method_id' => $pm->id, 'code' => 1, 'invcode' => 'TPY1', 'issued_at' => now(),
            'local_status' => 'active',
            'withhold_rate' => 20, 'withhold_category' => 1,   // 20% withholding, §8.4 cat 1 (reduces gross)
        ]);
        InvoiceLine::create([
            'company_id' => $tenant->id, 'invoice_id' => $inv->id,
            'qty' => 1, 'price_per_item' => 1000, 'vat_percent' => 24, // net 1000, gross 1240
        ]);

        app(RecomputeInvoiceTotals::class)($inv);
        $inv->refresh();

        // gross_total = net+VAT (revenue); withholding 200; payable = collectible.
        $this->assertSame('1240.00', (string) $inv->gross_total);
        $this->assertSame('200.00', (string) $inv->withhold_amount);
        $this->assertSame('1040.00', (string) $inv->payable_total);

        // Per-invoice: owed/balance reflect the payable (1040), gross stays 1240.
        $bal = app(InvoiceBalance::class)->for($inv);
        $this->assertEqualsWithDelta(1240.0, $bal->gross, 0.001);
        $this->assertEqualsWithDelta(1040.0, $bal->owed, 0.001);
        $this->assertEqualsWithDelta(1040.0, $bal->balance, 0.001);

        // Dashboard receivable = the reduced collectible (1040, NOT 1240).
        $this->assertEqualsWithDelta(1040.0, (new DashboardMetrics($tenant))->outstandingReceivables(), 0.001);
    }

    #[Test]
    public function fully_crediting_a_withholding_invoice_nets_to_zero(): void
    {
        $tenant = Company::create([
            'name' => 'WC', 'slug' => 'wc-'.uniqid(), 'country_code' => 'GR',
            'einvoice_provider' => 'gr-mydata', 'mydata_mode' => 'off', 'afm' => '800561849',
        ]);
        $saleType = InvoiceType::create(['company_id' => $tenant->id, 'code' => 'TPY', 'name' => 'ΤΠΥ', 'invcount' => 1]);
        $creditType = InvoiceType::create(['company_id' => $tenant->id, 'code' => 'ΠΙΣ', 'name' => 'Πιστωτικό', 'invcount' => 1, 'is_credit' => true]);
        $pm = PaymentMethod::create(['company_id' => $tenant->id, 'description' => '30 ημέρες', 'due_days' => 30]);
        $customer = Customer::create(['company_id' => $tenant->id, 'name' => 'Acme']);

        $inv = Invoice::create([
            'company_id' => $tenant->id, 'invoice_type_id' => $saleType->id, 'customer_id' => $customer->id,
            'payment_method_id' => $pm->id, 'code' => 1, 'invcode' => 'TPY1', 'issued_at' => now(),
            'local_status' => 'active', 'withhold_rate' => 20, 'withhold_category' => 1,
        ]);
        $line = InvoiceLine::create([
            'company_id' => $tenant->id, 'invoice_id' => $inv->id,
            'qty' => 1, 'price_per_item' => 1000, 'vat_percent' => 24,
        ]);
        app(RecomputeInvoiceTotals::class)($inv);
        $this->assertSame('1040.00', (string) $inv->fresh()->payable_total);

        // Full credit note — must reverse the withholding too (its payable = 1040).
        app(IssueCreditNote::class)($inv->fresh(), $creditType, [['line_id' => $line->id, 'qty' => 1]]);

        $inv->refresh();
        $this->assertEqualsWithDelta(0.0, app(InvoiceBalance::class)->for($inv)->owed, 0.005); // no phantom −200
        $this->assertTrue($inv->isFullyCredited());
        $this->assertEqualsWithDelta(0.0, (new DashboardMetrics($tenant))->outstandingReceivables(), 0.005);
    }

    #[Test]
    public function the_backfill_command_populates_payable_total_on_legacy_rows(): void
    {
        $tenant = Company::create([
            'name' => 'BF', 'slug' => 'bf-'.uniqid(), 'country_code' => 'GR', 'afm' => '800561849',
        ]);
        $type = InvoiceType::create(['company_id' => $tenant->id, 'code' => 'TPY', 'name' => 'ΤΠΥ', 'invcount' => 1]);
        $inv = Invoice::create([
            'company_id' => $tenant->id, 'invoice_type_id' => $type->id,
            'code' => 1, 'invcode' => 'TPY1', 'issued_at' => now(),
        ]);

        // Simulate a pre-migration row: gross + a fee already persisted, payable NULL.
        \Illuminate\Support\Facades\DB::table('invoices')->where('id', $inv->id)->update([
            'gross_total' => 124, 'fees_amount' => 10, 'fees_category' => 2, 'payable_total' => null,
        ]);

        $this->artisan('invoices:backfill-payable-total', ['--company' => $tenant->id])->assertSuccessful();

        $this->assertSame('134.00', (string) $inv->fresh()->payable_total); // 124 + 10 fee
    }
}
