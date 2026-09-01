<?php

namespace Tests\Feature\EInvoice;

use App\Models\Company;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\InvoiceType;
use App\Models\VatCategory;
use App\Services\EInvoice\AadeInvoiceDocument;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

/**
 * The opt-in <itemDescr> knob (companies.mydata_send_item_descr) on the MONETARY
 * builder (AadeInvoiceDocument).
 *
 * myDATA does NOT require the per-line description — the legacy app never sent it
 * (verified against imported legacy MARK XML, which carries only the E3 income
 * classification per line) — so default OFF keeps the request payload byte-identical
 * to the sandbox-validated shape, and AADE REJECTS itemDescr on a plain ΤΠΥ/ΤΙΜ
 * (spec line 1287). The monetary builder therefore never emits itemDescr for an
 * ordinary invoice, knob on or off.
 *
 * AADE accepts itemDescr only for delivery-note / shipping types (9.x). Since MYD-003
 * a 9.x type can no longer be a monetary invoice at all — AadeInvoiceDocument::build()
 * rejects it, and those documents go through the Delivery Notes flow where
 * DeliveryNoteSubmitter emits itemDescr. So on the monetary side the itemDescr branch
 * is currently unreachable; the day a combined invoice+delivery (1.1 with
 * isDeliveryNote=true) is modelled, allowsItemDescr() gates on that flag instead (see
 * docs/BACKLOG.md). These tests pin the monetary builder's actual behaviour today.
 */
class ItemDescrKnobTest extends TestCase
{
    use RefreshDatabase;

    private function makeInvoice(Company $tenant, string $mydataType, string $descr = 'Business20 - nac.gr'): Invoice
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

    private function xmlFor(Company $tenant, string $mydataType, string $descr = 'Business20 - nac.gr'): string
    {
        $doc = new AadeInvoiceDocument($tenant);

        return $doc->toXml($doc->build($this->makeInvoice($tenant, $mydataType, $descr)));
    }

    public function test_plain_monetary_invoice_omits_item_descr_with_knob_off(): void
    {
        $xml = $this->xmlFor($this->tenant(false), '2.1');

        $this->assertStringNotContainsString('<itemDescr>', $xml);
        $this->assertStringNotContainsString('Business20 - nac.gr', $xml);
    }

    public function test_plain_monetary_invoice_omits_item_descr_even_with_knob_on(): void
    {
        // 2.1 (ΤΠΥ) is a plain monetary type → AADE rejects itemDescr there, so the
        // knob must NOT emit it. This is the only shape the monetary builder ever
        // sees now (a 9.x can't be a monetary invoice — see below).
        $xml = $this->xmlFor($this->tenant(true), '2.1');

        $this->assertStringNotContainsString('<itemDescr>', $xml);
    }

    public function test_movement_only_type_cannot_be_built_as_a_monetary_invoice(): void
    {
        // MYD-003: a 9.x delivery type is rejected by the monetary builder up-front,
        // so the old "itemDescr emits for a 9.3 invoice" path is now unreachable here
        // — delivery-note itemDescr is emitted by DeliveryNoteSubmitter instead.
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('movement-only');

        $this->xmlFor($this->tenant(true), '9.3');
    }
}
