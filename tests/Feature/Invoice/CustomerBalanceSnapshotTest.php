<?php

namespace Tests\Feature\Invoice;

use App\Models\Company;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\InvoiceLine;
use App\Models\InvoiceType;
use App\Models\PaymentMethod;
use App\Services\InvoicePdfRenderer;
use App\Services\RecomputeInvoiceTotals;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * «Υπόλοιπο πελάτη» feature — Step 1 (snapshot-at-issue). When a credit-term
 * invoice is ISSUED (draft→active), the customer's running balance is captured
 * into `invoices.customer_balance_snapshot` so the PDF can print a stable
 * «Νέο υπόλοιπο» block. Cash-term invoices (settled at issue) are skipped.
 */
class CustomerBalanceSnapshotTest extends TestCase
{
    use RefreshDatabase;

    private function tenant(): Company
    {
        return Company::create([
            'name' => 'Snap', 'slug' => 'snap-'.uniqid(), 'country_code' => 'GR', 'afm' => '800561849',
        ]);
    }

    /** Issue a credit-term invoice for $customer with a single net/vat line. */
    private function issue(Company $tenant, Customer $customer, PaymentMethod $pm, InvoiceType $type, int $code, float $net, float $vat): Invoice
    {
        $inv = Invoice::create([
            'company_id' => $tenant->id, 'invoice_type_id' => $type->id, 'customer_id' => $customer->id,
            'payment_method_id' => $pm->id, 'code' => $code, 'invcode' => $type->code.$code, 'issued_at' => now(),
            'local_status' => 'draft',
        ]);
        InvoiceLine::create([
            'company_id' => $tenant->id, 'invoice_id' => $inv->id,
            'qty' => 1, 'price_per_item' => $net, 'vat_percent' => $vat,
        ]);
        app(RecomputeInvoiceTotals::class)($inv);

        // Finalize — this is the choke-point the observer snapshots on.
        $inv->refresh()->update(['local_status' => 'active']);

        return $inv->fresh();
    }

    #[Test]
    public function issuing_a_credit_term_invoice_captures_the_running_balance(): void
    {
        $tenant = $this->tenant();
        $type = InvoiceType::create(['company_id' => $tenant->id, 'code' => 'TPY', 'name' => 'ΤΠΥ', 'invcount' => 1]);
        $pm = PaymentMethod::create(['company_id' => $tenant->id, 'description' => '30 ημέρες', 'due_days' => 30]);
        $customer = Customer::create(['company_id' => $tenant->id, 'name' => 'Acme']);

        // First invoice: net 1000 + 24% = gross 1240, no prior balance.
        $a = $this->issue($tenant, $customer, $pm, $type, 1, 1000, 24);
        $this->assertSame('1240.00', (string) $a->customer_balance_snapshot);
        $this->assertEqualsWithDelta(1240.0, $a->customerBalanceContribution(), 0.005);
        // Προηγούμενο = snapshot − αυτό το παραστατικό = 0.
        $this->assertEqualsWithDelta(0.0, $a->customer_balance_snapshot - $a->customerBalanceContribution(), 0.005);

        // Second invoice for the SAME customer: snapshot is the cumulative balance.
        $type->refresh();
        $b = $this->issue($tenant, $customer, $pm, $type, 2, 500, 24); // gross 620
        $this->assertSame('1860.00', (string) $b->customer_balance_snapshot); // 1240 + 620
        $this->assertEqualsWithDelta(620.0, $b->customerBalanceContribution(), 0.005);
        // Προηγούμενο on the 2nd doc = the balance the 1st left behind (1240).
        $this->assertEqualsWithDelta(1240.0, $b->customer_balance_snapshot - $b->customerBalanceContribution(), 0.005);
    }

    #[Test]
    public function a_cash_term_invoice_is_not_snapshotted(): void
    {
        $tenant = $this->tenant();
        $type = InvoiceType::create(['company_id' => $tenant->id, 'code' => 'APY', 'name' => 'ΑΠΥ', 'invcount' => 1]);
        $cash = PaymentMethod::create(['company_id' => $tenant->id, 'description' => 'Μετρητά', 'due_days' => 0]);
        $customer = Customer::create(['company_id' => $tenant->id, 'name' => 'Retail']);

        $inv = $this->issue($tenant, $customer, $cash, $type, 1, 100, 24);

        $this->assertNull($inv->customer_balance_snapshot);
        $this->assertFalse($inv->affectsCustomerBalance());
        $this->assertEqualsWithDelta(0.0, $inv->customerBalanceContribution(), 0.005);
    }

    #[Test]
    public function the_snapshot_is_captured_once_and_is_stable_on_resave(): void
    {
        $tenant = $this->tenant();
        $type = InvoiceType::create(['company_id' => $tenant->id, 'code' => 'TPY', 'name' => 'ΤΠΥ', 'invcount' => 1]);
        $pm = PaymentMethod::create(['company_id' => $tenant->id, 'description' => '30 ημέρες', 'due_days' => 30]);
        $customer = Customer::create(['company_id' => $tenant->id, 'name' => 'Acme']);

        $a = $this->issue($tenant, $customer, $pm, $type, 1, 1000, 24);
        $this->assertSame('1240.00', (string) $a->customer_balance_snapshot);

        // A later invoice grows the balance, but re-saving the 1st must NOT
        // re-snapshot — the captured «Νέο υπόλοιπο» is frozen at issue time.
        $type->refresh();
        $this->issue($tenant, $customer, $pm, $type, 2, 500, 24);

        $a->refresh()->update(['notes' => 'touched']);
        $this->assertSame('1240.00', (string) $a->fresh()->customer_balance_snapshot);
    }

    /** Render the invoice PDF to HTML (skip the DomPDF binary stage). */
    private function html(Invoice $invoice): string
    {
        $renderer = app(InvoicePdfRenderer::class);
        $invoice->loadMissing(['lines', 'invoiceType', 'customer', 'company', 'paymentMethod']);
        $balance = (fn (Invoice $i) => $this->customerBalanceView($i))->call($renderer, $invoice);
        $totals = (fn (Invoice $i) => $this->totalsView($i))->call($renderer, $invoice);

        return view('invoices.pdf', [
            'invoice' => $invoice, 'tenant' => $invoice->company,
            'qrDataUri' => null, 'logoDataUri' => null,
            'totals' => $totals, 'customerBalance' => $balance,
            'L' => \App\Support\Pdf\PdfLabels::for('el'),
        ])->render();
    }

    #[Test]
    public function the_balance_block_prints_only_when_the_toggle_is_on(): void
    {
        $tenant = $this->tenant();
        $type = InvoiceType::create(['company_id' => $tenant->id, 'code' => 'TPY', 'name' => 'ΤΠΥ', 'invcount' => 1]);
        $pm = PaymentMethod::create(['company_id' => $tenant->id, 'description' => '30 ημέρες', 'due_days' => 30]);
        $customer = Customer::create(['company_id' => $tenant->id, 'name' => 'Acme']);

        $a = $this->issue($tenant, $customer, $pm, $type, 1, 1000, 24); // gross 1240

        // Default: tenant off, customer inherit → block omitted.
        $this->assertStringNotContainsString('Νέο υπόλοιπο', $this->html($a->fresh()));

        // Tenant default on → block printed (Προηγούμενο 0 / +1.240 / Νέο 1.240).
        $tenant->update(['show_customer_balance_on_pdf' => true]);
        $html = $this->html($a->fresh());
        $this->assertStringContainsString('Νέο υπόλοιπο', $html);
        $this->assertStringContainsString('Προηγούμενο υπόλοιπο', $html);
        $this->assertStringContainsString('+1.240,00', $html);

        // Per-customer override OFF wins over the tenant default.
        $customer->update(['show_balance_on_pdf' => false]);
        $this->assertStringNotContainsString('Νέο υπόλοιπο', $this->html($a->fresh()));
    }
}
