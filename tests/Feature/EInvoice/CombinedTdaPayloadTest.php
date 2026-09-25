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
 * Combined ΤΔΑ (Slice 3b): a monetary **1.1** that ALSO carries `isDeliveryNote=true`
 * + a movement header on the SAME document (ERP spec note 13). The monetary builder
 * (`AadeInvoiceDocument`) emits the movement block ONLY when the invoice's
 * `is_delivery_note` flag is set — a plain 1.1 stays byte-identical — and a ΤΔΑ, being
 * a δελτίο, may carry `<itemDescr>`.
 */
class CombinedTdaPayloadTest extends TestCase
{
    use RefreshDatabase;

    private function tenant(): Company
    {
        return Company::create([
            'name' => 'ΑΚΜΗ ΟΕ', 'slug' => 'tda-'.uniqid(), 'country_code' => 'GR',
            'einvoice_provider' => 'gr-mydata', 'afm' => '800561849',
            'tax_office' => 'ΚΕΦΟΔΕ', 'address' => 'ΑΔΡΙΑΝΟΥ 16', 'city' => 'ΑΘΗΝΑ', 'postcode' => '14121',
            'mydata_send_item_descr' => true,
        ]);
    }

    private function invoice(Company $tenant, array $overrides = []): Invoice
    {
        $customer = Customer::create([
            'company_id' => $tenant->id, 'name' => 'Πελάτης ΑΕ', 'afm' => '997073525',
        ]);
        $type = InvoiceType::create([
            'company_id' => $tenant->id, 'code' => 'ΤΔΑ', 'name' => 'Τιμολόγιο–Δελτίο Αποστολής',
            'invcount' => 1, 'mydata_type' => '1.1', 'is_delivery_note' => true,
        ]);
        VatCategory::create([
            'company_id' => $tenant->id, 'description' => '24%', 'rate' => 24, 'is_default' => true,
        ]);

        $invoice = Invoice::create(array_merge([
            'company_id' => $tenant->id, 'invcode' => 'ΤΔΑ1', 'code' => 1,
            'invoice_type_id' => $type->id, 'customer_id' => $customer->id,
            'issued_at' => now(), 'header_discount_percent' => 0,
            'company_name' => 'Πελάτης ΑΕ', 'vat_no' => '997073525',
            // A ΤΔΑ REQUIRES the counterpart name+address even for a GR party (AADE [204]
            // «Counterpart address is mandatory … IsDeliveryNote = true», sandbox 2026-09-14),
            // unlike a plain GR 1.1 where [219]/[220] forbid it. So the snapshot carries one.
            'address1' => 'Παραλήπτη', 'city' => 'Πάτρα', 'postcode' => '26221',
            'is_delivery_note' => true,
            'move_purpose' => 1,
            'dispatch_at' => now(),
            'vehicle_number' => 'ΙΑΒ1234',
            'loading_street' => 'Φόρτωσης', 'loading_number' => '10',
            'loading_postcode' => '11111', 'loading_city' => 'Αθήνα',
            'delivery_street' => 'Παράδοσης', 'delivery_number' => '20',
            'delivery_postcode' => '22222', 'delivery_city' => 'Θεσσαλονίκη',
        ], $overrides));
        $invoice->lines()->create([
            'company_id' => $tenant->id, 'product_descr' => 'Κιβώτια',
            'qty' => 3, 'price_per_item' => 100, 'vat_percent' => 24,
        ]);

        return $invoice->fresh('lines');
    }

    private function xml(Company $tenant, Invoice $invoice): string
    {
        $doc = new AadeInvoiceDocument($tenant);

        return $doc->toXml($doc->build($invoice));
    }

    public function test_taric_goes_on_a_tda_line_but_never_on_a_plain_1_1(): void
    {
        $tenant = $this->tenant();
        $tda = $this->invoice($tenant);
        $tda->lines()->first()->forceFill(['taric_code' => '8471300000', 'item_code' => 'SRV-R640'])->save();
        $xml = $this->xml($tenant, $tda->fresh('lines'));
        $this->assertStringContainsString('<TaricNo>8471300000</TaricNo>', $xml);
        $this->assertStringContainsString('<itemCode>SRV-R640</itemCode>', $xml);

        // A plain 1.1 (not a δελτίο): §5.4 accepts TaricNo/itemCode only on ΤΔΑ / 9.x.
        $other = $this->tenant();
        $plain = $this->invoice($other, ['invcode' => 'ΤΠΥ9', 'code' => 9, 'is_delivery_note' => false, 'move_purpose' => null]);
        $plain->lines()->first()->forceFill(['taric_code' => '8471300000', 'item_code' => 'SRV-R640'])->save();
        $xml2 = $this->xml($other, $plain->fresh('lines'));
        $this->assertStringNotContainsString('TaricNo', $xml2);
        $this->assertStringNotContainsString('itemCode', $xml2);
    }

    public function test_combined_tda_emits_the_movement_header_on_the_1_1(): void
    {
        $tenant = $this->tenant();
        $xml = $this->xml($tenant, $this->invoice($tenant));

        // The movement block rides on the SAME 1.1 document.
        $this->assertStringContainsString('isDeliveryNote', $xml);
        $this->assertStringContainsString('movePurpose', $xml);
        $this->assertStringContainsString('vehicleNumber', $xml);
        $this->assertStringContainsString('dispatchDate', $xml);
        $this->assertStringContainsString('otherDeliveryNoteHeader', $xml);
        $this->assertStringContainsString('loadingAddress', $xml);
        $this->assertStringContainsString('deliveryAddress', $xml);
        // …and a ΤΔΑ (a δελτίο) may carry <itemDescr> — the allowsItemDescr flag path.
        $this->assertStringContainsString('<itemDescr>', $xml);
        $this->assertStringContainsString('Κιβώτια', $xml);
        // Still a monetary document — currency is present (unlike a pure 9.x δελτίο).
        $this->assertStringContainsString('EUR', $xml);

        // Regression (sandbox 2026-09-14): a ΤΔΑ REQUIRES the counterpart's + issuer's
        // full identity even for a GR party — AADE rejects a missing one with [204],
        // and each goods line needs quantity + measurementUnit ([230]). A plain GR 1.1
        // omits all of these; the ΤΔΑ path must add them.
        $issuer = $this->section($xml, 'issuer');
        $this->assertStringContainsString('<name>', $issuer, 'ΤΔΑ issuer must carry a name');
        $this->assertStringContainsString('<address>', $issuer, 'ΤΔΑ issuer must carry an address');
        $this->assertStringContainsString('ΑΔΡΙΑΝΟΥ 16', $issuer);

        $counterpart = $this->section($xml, 'counterpart');
        $this->assertStringContainsString('<name>', $counterpart, 'ΤΔΑ counterpart must carry a name');
        $this->assertStringContainsString('<address>', $counterpart, 'ΤΔΑ counterpart must carry an address');
        $this->assertStringContainsString('Παραλήπτη', $counterpart);
        $this->assertStringContainsString('<number>', $counterpart, 'ΤΔΑ counterpart address needs a number ([204])');

        // Goods movement: per-line quantity + measurementUnit (mandatory for a ΤΔΑ, [230]).
        $this->assertStringContainsString('<quantity>', $xml);
        $this->assertStringContainsString('measurementUnit', $xml);
    }

    /**
     * Inner text of the first <tag>…</tag> block. The AADE invoice payload serialises
     * issuer/counterpart UNPREFIXED (default namespace), so a bare-tag match is correct;
     * callers add an assertNotEmpty guard so a future prefixed serialisation can't turn a
     * negative assertion into a vacuous pass.
     */
    private function section(string $xml, string $tag): string
    {
        return preg_match('#<'.$tag.'>(.*?)</'.$tag.'>#s', $xml, $m) ? $m[1] : '';
    }

    public function test_tracking_off_emits_without_digital_transport_tracking(): void
    {
        $tenant = $this->tenant();
        $xml = $this->xml($tenant, $this->invoice($tenant, ['without_digital_transport_tracking' => true]));

        $this->assertStringContainsString('withoutDigitalTransportTracking', $xml);
    }

    public function test_tda_without_move_purpose_fails_loud(): void
    {
        // movePurpose is mandatory for a δελτίο — a ΤΔΑ with none must fail at the
        // builder (a clear message), never emit isDeliveryNote without movePurpose
        // for AADE to reject opaquely. Matches DeliveryNoteSubmitter.
        $tenant = $this->tenant();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/σκοπός διακίνησης/u');

        $this->xml($tenant, $this->invoice($tenant, ['move_purpose' => null]));
    }

    public function test_move_purpose_19_without_title_fails_loud(): void
    {
        // σκοπός 19 (Λοιπές Διακινήσεις) requires the free-text title — fail loud
        // rather than emit an untitled 19 that AADE rejects opaquely.
        $tenant = $this->tenant();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/σκοπός 19/u');

        $this->xml($tenant, $this->invoice($tenant, ['move_purpose' => 19, 'other_move_purpose_title' => null]));
    }

    public function test_plain_1_1_invoice_omits_the_movement_header(): void
    {
        $tenant = $this->tenant();
        // Same movement data on the row, but is_delivery_note=false → the builder must
        // emit NONE of the movement block (a plain 1.1 stays byte-identical).
        $xml = $this->xml($tenant, $this->invoice($tenant, ['is_delivery_note' => false]));

        $this->assertStringNotContainsString('isDeliveryNote', $xml);
        $this->assertStringNotContainsString('otherDeliveryNoteHeader', $xml);

        // Byte-identical guarantee: the ΤΔΑ counterpart/issuer name+address additions are
        // gated on is_delivery_note, so a plain GR 1.1 keeps the bare GR counterpart
        // ([219]/[220] forbid its name/address) even though the row carries an address.
        $counterpart = $this->section($xml, 'counterpart');
        // Guard against a vacuous pass: a plain B2B GR 1.1 still files a counterpart
        // (vatNumber/country/branch), so the negatives below assert a real absence of
        // name/address, not a section() extraction miss.
        $this->assertNotEmpty($counterpart, 'plain 1.1 must still file a counterpart');
        $this->assertStringContainsString('<vatNumber>', $counterpart);
        $this->assertStringNotContainsString('<name>', $counterpart);
        $this->assertStringNotContainsString('<address>', $counterpart);
        $this->assertStringNotContainsString('measurementUnit', $xml);
    }

    public function test_is_delivery_note_on_a_services_type_fails_the_goods_type_guard(): void
    {
        // 3d-b guard: the form toggle is exposed on every type, so an operator could flip
        // is_delivery_note on a SERVICES type (2.1) — which AADE's supportsDeliveryNote does
        // NOT allow (services emit no per-line <quantity>; a movement needs quantities). The
        // builder must refuse with a clear Greek message, not emit a contradictory payload.
        $tenant = $this->tenant();

        $customer = Customer::create([
            'company_id' => $tenant->id, 'name' => 'Πελάτης ΑΕ', 'afm' => '997073525',
        ]);
        $type = InvoiceType::create([
            'company_id' => $tenant->id, 'code' => 'ΤΠΥΔ', 'name' => 'ΤΠΥ ως δελτίο (άκυρο)',
            'invcount' => 1, 'mydata_type' => '2.1', 'is_delivery_note' => true,
            'mydata_income_class' => 'E3_561_001', 'mydata_income_class_category' => 'category1_3',
        ]);
        VatCategory::create(['company_id' => $tenant->id, 'description' => '24%', 'rate' => 24, 'is_default' => true]);

        $invoice = Invoice::create([
            'company_id' => $tenant->id, 'invcode' => 'ΤΠΥΔ1', 'code' => 1,
            'invoice_type_id' => $type->id, 'customer_id' => $customer->id,
            'issued_at' => now(), 'header_discount_percent' => 0,
            'company_name' => 'Πελάτης ΑΕ', 'vat_no' => '997073525',
            'is_delivery_note' => true, 'move_purpose' => 1, 'vehicle_number' => 'ΙΑΒ1234',
        ]);
        $invoice->lines()->create([
            'company_id' => $tenant->id, 'product_descr' => 'Υπηρεσία',
            'qty' => 1, 'price_per_item' => 100, 'vat_percent' => 24,
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/δεν υποστηρίζει δελτίο αποστολής/u');

        $this->xml($tenant, $invoice->fresh('lines'));
    }
}
