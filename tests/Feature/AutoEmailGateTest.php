<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\InvoiceType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * G6 — the two-level auto-email gate.
 *
 *   - Per-customer opt-out (customers.auto_email_invoices, default true)
 *     governs BOTH automatic paths.
 *   - Company toggle (companies.auto_email_on_issue, default false) opens
 *     the NON-myDATA finalize path.
 *   - myDATA tenants (sandbox/production) never auto-email on finalize —
 *     they get the mail on the VALID response, so finalize would double-send.
 *
 * These lock the decision predicates (Invoice::customerAcceptsAutoEmail +
 * shouldAutoEmailOnFinalize) that the finalize action and MyDataSubmitter
 * both consult.
 */
class AutoEmailGateTest extends TestCase
{
    use RefreshDatabase;

    private function tenant(array $overrides = []): Company
    {
        return Company::create(array_merge([
            'name' => 'Auto Co',
            'slug' => 'auto-'.uniqid(),
            'country_code' => 'GR',
            'einvoice_provider' => 'none',
            'mydata_mode' => 'off',
            'auto_email_on_issue' => false,
        ], $overrides));
    }

    private function invoiceFor(Company $tenant, Customer $customer): Invoice
    {
        $type = InvoiceType::create([
            'company_id' => $tenant->id,
            'code' => 'APY',
            'name' => 'Απόδειξη',
            'invcount' => 1,
            'mydata_type' => '2.1',
        ]);

        return Invoice::create([
            'company_id' => $tenant->id,
            'invcode' => 'APY1',
            'code' => 1,
            'invoice_type_id' => $type->id,
            'customer_id' => $customer->id,
            'issued_at' => now(),
            'net_total' => 10.00,
            'gross_total' => 12.40,
            'header_discount_percent' => 0,
            'mydata_sent' => false,
        ]);
    }

    private function customerFor(Company $tenant, array $overrides = []): Customer
    {
        return Customer::create(array_merge([
            'company_id' => $tenant->id,
            'name' => 'Πελάτης',
            'email' => 'c@example.test',
            'auto_email_invoices' => true,
        ], $overrides));
    }

    public function test_customer_opt_out_defaults_to_true_when_unset(): void
    {
        $tenant = $this->tenant();
        // No auto_email_invoices passed → column default true.
        $customer = Customer::create([
            'company_id' => $tenant->id,
            'name' => 'Default',
        ]);
        $invoice = $this->invoiceFor($tenant, $customer);

        $this->assertTrue($invoice->customerAcceptsAutoEmail());
    }

    public function test_customer_opt_out_respected(): void
    {
        $tenant = $this->tenant();
        $customer = $this->customerFor($tenant, ['auto_email_invoices' => false]);
        $invoice = $this->invoiceFor($tenant, $customer);

        $this->assertFalse($invoice->customerAcceptsAutoEmail());
    }

    public function test_finalize_emails_when_tenant_opted_in_and_customer_in(): void
    {
        $tenant = $this->tenant(['auto_email_on_issue' => true]);
        $customer = $this->customerFor($tenant);
        $invoice = $this->invoiceFor($tenant, $customer);

        $this->assertTrue($invoice->shouldAutoEmailOnFinalize());
    }

    public function test_finalize_blocked_when_company_toggle_off(): void
    {
        $tenant = $this->tenant(['auto_email_on_issue' => false]);
        $customer = $this->customerFor($tenant);
        $invoice = $this->invoiceFor($tenant, $customer);

        $this->assertFalse($invoice->shouldAutoEmailOnFinalize());
    }

    public function test_finalize_blocked_when_customer_opted_out(): void
    {
        $tenant = $this->tenant(['auto_email_on_issue' => true]);
        $customer = $this->customerFor($tenant, ['auto_email_invoices' => false]);
        $invoice = $this->invoiceFor($tenant, $customer);

        $this->assertFalse($invoice->shouldAutoEmailOnFinalize());
    }

    public function test_finalize_blocked_for_mydata_tenants_to_avoid_double_send(): void
    {
        // Even with both toggles on, a myDATA-filing tenant must NOT
        // auto-email on finalize — the VALID response path owns that.
        $tenant = $this->tenant([
            'einvoice_provider' => 'gr-mydata',
            'mydata_mode' => 'production',
            'auto_email_on_issue' => true,
        ]);
        $customer = $this->customerFor($tenant);
        $invoice = $this->invoiceFor($tenant, $customer);

        $this->assertFalse($invoice->shouldAutoEmailOnFinalize());
    }

    public function test_finalize_blocked_for_provider_tenants_to_avoid_double_send(): void
    {
        // A gr-provider (InvoSign…) tenant files ELECTRONICALLY too, but its
        // mydata_mode is 'off' — so a raw mydata_mode check would miss it and
        // auto-email on finalize, on top of the provider's own acceptance email
        // (GrProviderSubmitter now queues it), double-sending. shouldAutoEmail-
        // OnFinalize must treat the provider as electronic and return false.
        $tenant = $this->tenant([
            'einvoice_provider' => 'gr-provider',
            'einvoice_provider_key' => 'invosign',
            'einvoice_provider_mode' => 'production',
            'mydata_mode' => 'off',
            'auto_email_on_issue' => true,
        ]);
        $customer = $this->customerFor($tenant);
        $invoice = $this->invoiceFor($tenant, $customer);

        $this->assertFalse($invoice->shouldAutoEmailOnFinalize());
    }
}
