<?php

namespace Tests\Feature\Invoice;

use App\Models\BankAccount;
use App\Models\Company;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\InvoiceLine;
use App\Models\InvoiceType;
use App\Models\PaymentMethod;
use App\Services\InvoicePdfRenderer;
use App\Services\RecomputeInvoiceTotals;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class InvoicePdfBankAccountsTest extends TestCase
{
    use RefreshDatabase;

    private function tenant(): Company
    {
        return Company::create([
            'name' => 'Bank OE', 'slug' => 'bank-'.uniqid(), 'country_code' => 'GR', 'afm' => '800561849',
        ]);
    }

    public function test_invoice_accounts_returns_only_active_and_shown_scoped_to_tenant(): void
    {
        $a = $this->tenant();
        $b = $this->tenant();

        $shown = BankAccount::create(['company_id' => $a->id, 'bank_name' => 'Alpha', 'iban' => 'GR-ALPHA', 'is_active' => true, 'show_on_invoices' => true]);
        BankAccount::create(['company_id' => $a->id, 'bank_name' => 'Piraeus', 'iban' => 'GR-PIR', 'is_active' => true, 'show_on_invoices' => false]); // hidden
        BankAccount::create(['company_id' => $a->id, 'bank_name' => 'Eurobank', 'iban' => 'GR-EURO', 'is_active' => false, 'show_on_invoices' => true]); // inactive
        BankAccount::create(['company_id' => $b->id, 'bank_name' => 'Other', 'iban' => 'GR-OTHER', 'is_active' => true, 'show_on_invoices' => true]); // other tenant

        $accounts = BankAccount::invoiceAccounts($a->id);

        $this->assertCount(1, $accounts);
        $this->assertSame($shown->id, $accounts->first()->id);
    }

    public function test_invoice_pdf_html_lists_all_payment_accounts_and_total_quantity(): void
    {
        $tenant = $this->tenant();
        $type = InvoiceType::create(['company_id' => $tenant->id, 'code' => 'TPY', 'name' => 'ΤΠΥ', 'invcount' => 1]);
        $pm = PaymentMethod::create(['company_id' => $tenant->id, 'description' => 'Κατάθεση', 'due_days' => 0]);
        $customer = Customer::create(['company_id' => $tenant->id, 'name' => 'Πελάτης']);

        BankAccount::create(['company_id' => $tenant->id, 'bank_name' => 'Alpha Bank', 'iban' => 'GR1100001111', 'swift' => 'CRBAGRAA', 'is_active' => true, 'show_on_invoices' => true]);
        BankAccount::create(['company_id' => $tenant->id, 'bank_name' => 'Piraeus Bank', 'iban' => 'GR2200002222', 'is_active' => true, 'show_on_invoices' => true]);
        BankAccount::create(['company_id' => $tenant->id, 'bank_name' => 'Payroll', 'iban' => 'GR9900009999', 'is_active' => true, 'show_on_invoices' => false]);

        $inv = Invoice::create([
            'company_id' => $tenant->id, 'invoice_type_id' => $type->id, 'customer_id' => $customer->id,
            'payment_method_id' => $pm->id, 'code' => 1, 'invcode' => 'TPY1', 'issued_at' => now(),
            'local_status' => 'active',
        ]);
        InvoiceLine::create(['company_id' => $tenant->id, 'invoice_id' => $inv->id, 'qty' => 2, 'price_per_item' => 50, 'vat_percent' => 24, 'product_descr' => 'Item A']);
        InvoiceLine::create(['company_id' => $tenant->id, 'invoice_id' => $inv->id, 'qty' => 3, 'price_per_item' => 10, 'vat_percent' => 24, 'product_descr' => 'Item B']);
        app(RecomputeInvoiceTotals::class)($inv);

        $html = app(InvoicePdfRenderer::class)->renderHtml($inv->fresh());

        // Both shown accounts (IBAN) printed; the hidden one is NOT.
        $this->assertStringContainsString('GR1100001111', $html);
        $this->assertStringContainsString('GR2200002222', $html);
        $this->assertStringNotContainsString('GR9900009999', $html);
        // Total quantity = 2 + 3 = 5.
        $this->assertStringContainsString('Συνολική ποσότητα', $html);
        $this->assertStringContainsString('5,000', $html);
    }
}
