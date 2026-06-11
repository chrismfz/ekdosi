<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\InvoiceLine;
use App\Models\InvoiceType;
use App\Models\PaymentMethod;
use App\Services\RecomputeInvoiceTotals;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The overdue digest must total the COLLECTIBLE (payable_total) — a withholding
 * invoice owes net of the παρακράτηση. Regression guard: the command's query must
 * SELECT payable_total, else payableTotal() silently falls back to gross.
 */
class NotifyOverdueInvoicesTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function the_overdue_digest_totals_the_payable_not_the_gross(): void
    {
        $tenant = Company::create([
            'name' => 'OD', 'slug' => 'od-'.uniqid(), 'country_code' => 'GR',
            'einvoice_provider' => 'gr-mydata', 'mydata_mode' => 'off', 'afm' => '800561849',
        ]);
        $type = InvoiceType::create(['company_id' => $tenant->id, 'code' => 'TPY', 'name' => 'ΤΠΥ', 'invcount' => 1]);
        $pm = PaymentMethod::create(['company_id' => $tenant->id, 'description' => '30 ημέρες', 'due_days' => 30]);
        $customer = Customer::create(['company_id' => $tenant->id, 'name' => 'Acme']);

        $inv = Invoice::create([
            'company_id' => $tenant->id, 'invoice_type_id' => $type->id, 'customer_id' => $customer->id,
            'payment_method_id' => $pm->id, 'code' => 1, 'invcode' => 'TPY1',
            'issued_at' => now()->subDays(60), 'local_status' => 'active',  // due 30 days ago → overdue
            'withhold_rate' => 20, 'withhold_category' => 1,
        ]);
        InvoiceLine::create([
            'company_id' => $tenant->id, 'invoice_id' => $inv->id,
            'qty' => 1, 'price_per_item' => 1000, 'vat_percent' => 24, // gross 1240, payable 1040
        ]);
        app(RecomputeInvoiceTotals::class)($inv);

        $this->artisan('invoices:notify-overdue', ['--dry-run' => true])
            ->expectsOutputToContain('1.040,00')      // payable — NOT 1.240,00 (gross)
            ->assertSuccessful();
    }
}
