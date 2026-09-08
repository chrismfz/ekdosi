<?php

namespace Tests\Feature;

use App\Mail\InvoiceIssuedMail;
use App\Models\Company;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\InvoiceType;
use App\Models\Quote;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Mail\Markdown;
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
            'gemi' => '123456789000',
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
        // The tenant contact footer carries ΑΦΜ and — when present — ΓΕΜΗ.
        $this->assertStringContainsString('ΑΦΜ: 800561849', $html);
        $this->assertStringContainsString('ΓΕΜΗ: 123456789000', $html);
    }

    public function test_plaintext_footer_carries_afm_and_gemi_and_no_app_name(): void
    {
        config(['app.name' => 'EKDOSI-INTERNAL-XYZ']);

        $tenant = Company::create([
            'name' => 'MyIP Networks OE', 'slug' => 'brand-'.uniqid(), 'country_code' => 'GR',
            'einvoice_provider' => 'gr-mydata', 'mydata_mode' => 'off',
            'phone' => '2101234567', 'email' => 'info@myip.gr', 'afm' => '800561849',
            'gemi' => '123456789000',
        ]);

        // The plaintext MIME part (custom text view — no markdown chrome).
        $text = view('mail.invoice.issued_text', [
            'tenant' => $tenant,
            'bodyText' => 'Σας αποστέλλουμε το παραστατικό.',
        ])->render();

        $this->assertStringContainsString('MyIP Networks OE', $text);
        $this->assertStringContainsString('ΑΦΜ: 800561849', $text);
        $this->assertStringContainsString('ΓΕΜΗ: 123456789000', $text);
        $this->assertStringNotContainsString('EKDOSI-INTERNAL-XYZ', $text);
    }

    public function test_gemi_line_is_omitted_when_the_company_has_none(): void
    {
        $tenant = Company::create([
            'name' => 'Απλή Εταιρεία ΟΕ', 'slug' => 'brand-'.uniqid(), 'country_code' => 'GR',
            'einvoice_provider' => 'gr-mydata', 'mydata_mode' => 'off',
            'afm' => '800561849', // no gemi
        ]);

        $text = view('mail.invoice.issued_text', [
            'tenant' => $tenant,
            'bodyText' => 'Σας αποστέλλουμε το παραστατικό.',
        ])->render();

        $this->assertStringContainsString('ΑΦΜ: 800561849', $text);
        $this->assertStringNotContainsString('ΓΕΜΗ:', $text, 'no ΓΕΜΗ label when the company has none');
    }

    public function test_auto_derived_plaintext_has_no_app_name_chrome(): void
    {
        // The quote email specifies NO explicit text view, so Laravel auto-derives the
        // plaintext through text/message.blade.php → the vendor text/header + text/footer
        // overrides. (The invoice's text part is a CUSTOM view, so only this exercises
        // those two overrides.) Locks that they drop the app-name header + «© <app>»
        // footer from the plaintext MIME part.
        config(['app.name' => 'EKDOSI-INTERNAL-XYZ']);

        $tenant = Company::create([
            'name' => 'MyIP Networks OE', 'slug' => 'brand-'.uniqid(), 'country_code' => 'GR',
            'einvoice_provider' => 'gr-mydata', 'mydata_mode' => 'off',
        ]);
        $quote = (new Quote)->forceFill(['code' => 'PROSF-1', 'gross_total' => 100]);

        $text = (string) app(Markdown::class)->renderText('mail.quote.offer', [
            'tenant' => $tenant, 'quote' => $quote,
        ]);

        $this->assertStringContainsString('MyIP Networks OE', $text, 'company branding present');
        $this->assertStringNotContainsString('EKDOSI-INTERNAL-XYZ', $text, 'no app-name banner in the plaintext');
        $this->assertStringNotContainsString('All rights reserved', $text, 'no © app-name footer in the plaintext');
    }
}
