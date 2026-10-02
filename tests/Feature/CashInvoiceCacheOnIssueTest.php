<?php

namespace Tests\Feature;

use App\Enums\PaymentStatus;
use App\Models\Company;
use App\Models\Invoice;
use App\Models\InvoiceType;
use App\Models\PaymentMethod;
use App\Services\InvoiceBalance;
use App\Services\RecomputeInvoiceTotals;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * A cash-term invoice is «unpaid» as an unissued draft and settled the moment it
 * is issued. The CACHED payment_status (what lists/badges read) must follow that
 * transition — it used to stay «unpaid» from the draft-time recompute while the
 * live balance said paid.
 */
class CashInvoiceCacheOnIssueTest extends TestCase
{
    use RefreshDatabase;

    public function test_issuing_a_cash_invoice_refreshes_its_cached_status(): void
    {
        $company = Company::create(['name' => 'C', 'slug' => 'cc-'.uniqid(), 'country_code' => 'GR', 'einvoice_provider' => 'none']);
        $type = InvoiceType::create(['company_id' => $company->id, 'code' => 'ΤΠΥ', 'name' => 'ΤΠΥ', 'invcount' => 1, 'mydata_type' => '2.1']);
        $cash = PaymentMethod::create(['company_id' => $company->id, 'description' => 'Μετρητά', 'due_days' => 0]);
        $credit = PaymentMethod::create(['company_id' => $company->id, 'description' => 'Επί πιστώσει', 'due_days' => 30]);

        $invoice = Invoice::create(['company_id' => $company->id, 'invoice_type_id' => $type->id, 'issued_at' => now(), 'local_status' => 'draft', 'payment_method_id' => $cash->id]);
        $invoice->lines()->create(['company_id' => $company->id, 'product_descr' => 'x', 'qty' => 1, 'price_per_item' => 10, 'vat_percent' => 24]);
        app(RecomputeInvoiceTotals::class)($invoice);
        $this->assertSame(PaymentStatus::Unpaid, $this->cached($invoice), 'an unissued draft is not settled');

        $invoice->fresh()->update(['local_status' => 'active']);
        $this->assertSame(PaymentStatus::Paid, $this->cached($invoice));
        $this->assertSame(app(InvoiceBalance::class)->for($invoice->fresh())->status, $this->cached($invoice), 'cache == live');

        // Switching a draft to a credit-term method flips it back to a receivable.
        $other = Invoice::create(['company_id' => $company->id, 'invoice_type_id' => $type->id, 'issued_at' => now(), 'local_status' => 'active', 'payment_method_id' => $cash->id]);
        $other->lines()->create(['company_id' => $company->id, 'product_descr' => 'y', 'qty' => 1, 'price_per_item' => 10, 'vat_percent' => 24]);
        app(RecomputeInvoiceTotals::class)($other);
        $other->fresh()->update(['payment_method_id' => $credit->id]);
        $this->assertSame(PaymentStatus::Unpaid, $this->cached($other));
    }

    private function cached(Invoice $invoice): PaymentStatus
    {
        $value = $invoice->fresh()->payment_status;

        return $value instanceof PaymentStatus ? $value : PaymentStatus::from((string) $value);
    }
}
