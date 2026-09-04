<?php

namespace Tests\Feature;

use App\Mail\InvoiceIssuedMail;
use App\Models\Company;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\InvoiceType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Customer-facing mail must be branded by the ISSUING COMPANY, never by the
 * internal app name (config('app.name') = «ekdosi»). Laravel's default markdown-mail
 * chrome injects config('app.name') twice — the header banner and the "© <year>
 * <app name>. All rights reserved." footer — which meant test recipients saw
 * «ekdosi» on real invoices. The vendor overrides in resources/views/vendor/mail
 * drop that chrome; this test locks it so a framework update can't quietly bring the
 * banner back.
 */
class MailBrandingTest extends TestCase
{
    use RefreshDatabase;

    public function test_invoice_email_carries_the_company_name_not_the_app_name(): void
    {
        // A distinctive internal app name so its presence in the output is unambiguous.
        config(['app.name' => 'EKDOSI-INTERNAL-XYZ']);

        $tenant = Company::create([
            'name' => 'MyIP Networks OE', 'slug' => 'brand-'.uniqid(), 'country_code' => 'GR',
            'einvoice_provider' => 'gr-mydata', 'mydata_mode' => 'off',
            'phone' => '2101234567', 'email' => 'info@myip.gr', 'afm' => '800561849',
        ]);
        $customer = Customer::create([
            'company_id' => $tenant->id, 'name' => 'Πελάτης ΑΕ', 'email' => 'cust@example.com',
        ]);
        $type = InvoiceType::create([
            'company_id' => $tenant->id, 'code' => 'TPY', 'name' => 'Τιμολόγιο',
            'invcount' => 1, 'mydata_type' => '1.1',
        ]);
        $invoice = Invoice::create([
            'company_id' => $tenant->id, 'invcode' => 'TPY6665', 'code' => 6665,
            'invoice_type_id' => $type->id, 'customer_id' => $customer->id,
            'issued_at' => now(), 'header_discount_percent' => 0,
            'company_name' => 'Πελάτης ΑΕ', 'vat_no' => '997073525',
        ]);
        $invoice->lines()->create([
            'company_id' => $tenant->id, 'product_descr' => 'Υπηρεσία',
            'qty' => 1, 'price_per_item' => 150, 'vat_percent' => 24,
        ]);

        $html = (new InvoiceIssuedMail($invoice->fresh('lines'), 'PDFBYTES'))->render();

        // The issuing company IS the branding.
        $this->assertStringContainsString('MyIP Networks OE', $html);
        // The internal app name is NOT anywhere in the mail (header banner gone).
        $this->assertStringNotContainsString('EKDOSI-INTERNAL-XYZ', $html);
        // The default "© <year> <app name>. All rights reserved." footer is gone too.
        $this->assertStringNotContainsString('All rights reserved', $html);
    }
}
