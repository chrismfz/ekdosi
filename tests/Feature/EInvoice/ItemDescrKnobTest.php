<?php

namespace Tests\Feature\EInvoice;

use App\Models\Company;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\InvoiceType;
use App\Models\VatCategory;
use App\Services\EInvoice\AadeInvoiceDocument;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The opt-in <itemDescr> knob (companies.mydata_send_item_descr).
 *
 * myDATA does NOT require the per-line description — the legacy app never sent
 * it (verified against imported legacy MARK XML, which carries only the E3
 * income classification per line). So default OFF keeps the request payload
 * byte-identical to the sandbox-validated shape.
 *
 * AADE additionally ACCEPTS itemDescr ONLY for delivery-note / shipping types
 * (9.x) — it REJECTS it on a plain ΤΠΥ/ΤΙΜ (spec line 1287) — so even with the
 * knob on, emission is gated by document type and can never cause a rejection.
 */
class ItemDescrKnobTest extends TestCase
{
    use RefreshDatabase;

    private function makeInvoice(Company $tenant, string $mydataType = '9.3', string $descr = 'Business20 - nac.gr'): Invoice
    {
        $customer = Customer::create([
            'company_id' => $tenant->id, 'name' => 'Πελάτης ΑΕ', 'afm' => '997073525',
        ]);
        $type = InvoiceType::create([
            'company_id' => $tenant->id, 'code' => 'TPY', 'name' => 'ΤΠΥ',
            'invcount' => 1, 'mydata_type' => $mydataType,
        ]);
        VatCategory::create([
            'company_id' => $tenant->id, 'description' => '24%', 'rate' => 24, 'is_default' => true,
        ]);

        $invoice = Invoice::create([
            'company_id' => $tenant->id, 'invcode' => 'TPY1', 'code' => 1,
            'invoice_type_id' => $type->id, 'customer_id' => $customer->id,
            'issued_at' => now(), 'header_discount_percent' => 0,
            'company_name' => 'Πελάτης ΑΕ', 'vat_no' => '997073525',
        ]);
        $invoice->lines()->create([
            'company_id' => $tenant->id, 'product_descr' => $descr,
            'qty' => 1, 'price_per_item' => 100, 'vat_percent' => 24,
        ]);

        return $invoice->fresh('lines');
    }

    private function tenant(bool $sendItemDescr): Company
    {
        return Company::create([
            'name' => 'ΑΚΜΗ ΟΕ', 'slug' => 'descr-'.uniqid(), 'country_code' => 'GR',
            'einvoice_provider' => 'gr-mydata', 'afm' => '800561849',
            'tax_office' => 'ΚΕΦΟΔΕ', 'address' => 'ΑΔΡΙΑΝΟΥ 16', 'city' => 'ΑΘΗΝΑ', 'postcode' => '14121',
            'mydata_send_item_descr' => $sendItemDescr,
        ]);
    }

    private function xmlFor(Company $tenant, string $mydataType = '9.3', string $descr = 'Business20 - nac.gr'): string
    {
        $doc = new AadeInvoiceDocument($tenant);

        return $doc->toXml($doc->build($this->makeInvoice($tenant, $mydataType, $descr)));
    }

    public function test_default_omits_item_descr(): void
    {
        // Even on an eligible (9.3) type, the knob OFF means no itemDescr.
        $xml = $this->xmlFor($this->tenant(false), '9.3');

        $this->assertStringNotContainsString('<itemDescr>', $xml);
        $this->assertStringNotContainsString('Business20 - nac.gr', $xml);
    }

    public function test_knob_on_emits_for_delivery_note_type(): void
    {
        $xml = $this->xmlFor($this->tenant(true), '9.3');

        $this->assertStringContainsString('<itemDescr>Business20 - nac.gr</itemDescr>', $xml);
    }

    public function test_knob_on_omits_for_plain_invoice_type_aade_would_reject(): void
    {
        // 2.1 (ΤΠΥ) is NOT a delivery-note type → AADE rejects itemDescr there,
        // so we must NOT emit it even with the knob on.
        $xml = $this->xmlFor($this->tenant(true), '2.1');

        $this->assertStringNotContainsString('<itemDescr>', $xml);
    }

    public function test_knob_on_with_blank_description_omits_item_descr(): void
    {
        $xml = $this->xmlFor($this->tenant(true), '9.3', '');

        $this->assertStringNotContainsString('<itemDescr>', $xml);
    }

    public function test_knob_on_clamps_long_description_to_256_chars(): void
    {
        $xml = $this->xmlFor($this->tenant(true), '9.3', str_repeat('Α', 400));

        $this->assertStringContainsString('<itemDescr>'.str_repeat('Α', 256).'</itemDescr>', $xml);
        $this->assertStringNotContainsString(str_repeat('Α', 257), $xml);
    }
}
