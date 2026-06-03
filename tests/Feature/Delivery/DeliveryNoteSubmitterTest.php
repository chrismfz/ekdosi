<?php

namespace Tests\Feature\Delivery;

use App\Models\Company;
use App\Models\Customer;
use App\Models\DeliveryMark;
use App\Models\DeliveryNote;
use App\Models\DeliveryNoteLine;
use App\Models\InvoiceType;
use App\Services\Delivery\DeliveryNoteSubmitter;
use Firebed\AadeMyData\Models\Invoice as AadeInvoice;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\Psr7\Response as GuzzleResponse;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * No-network coverage for DeliveryNoteSubmitter. Build + previewXml run with no
 * AADE call; the live submit() happy-path uses a Guzzle MockHandler (same seam
 * as MyDataSubmitter's $mockHandler / firebed's MyDataRequest::setHandler).
 *
 * The asserted 9.3 value-less shape is grounded in firebed's reference payload
 * vendor/firebed/aade-mydata/stubs/request-doc-with-delivery-lifecycle.xml.
 */
class DeliveryNoteSubmitterTest extends TestCase
{
    use RefreshDatabase;

    private Company $tenant;

    private InvoiceType $deliveryType;

    private Customer $recipient;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Company::create([
            'name' => 'Delivery test',
            'slug' => 'deliv-'.uniqid(),
            'country_code' => 'GR',
            'einvoice_provider' => 'gr-mydata',
            'mydata_mode' => 'sandbox',
            'afm' => '800561849',
            'mydata_aade_id_sandbox' => 'TESTUSER',
            'mydata_subscription_key_sandbox' => 'TESTKEY',
        ]);

        $this->deliveryType = InvoiceType::create([
            'company_id' => $this->tenant->id,
            'code' => 'DA',
            'name' => 'Δελτίο Αποστολής',
            'invcount' => 1,
            'mydata_type' => '9.3',
        ]);

        $this->recipient = Customer::create([
            'company_id' => $this->tenant->id,
            'name' => 'Παραλήπτης ΑΕ',
            'afm' => '123456789',
        ]);
    }

    private function makeNote(array $overrides = []): DeliveryNote
    {
        $note = DeliveryNote::create(array_merge([
            'company_id' => $this->tenant->id,
            'invcode' => 'DA1',
            'code' => 1,
            'delivery_type_id' => $this->deliveryType->id,
            'customer_id' => $this->recipient->id,
            'issued_at' => now(),
            'mydata_type' => '9.3',
            'move_purpose' => 8,                 // Ενδοδιακίνηση
            'dispatch_at' => now()->addHour(),
            'vehicle_number' => 'ΙΑΒ1234',
            'third_party_collection' => false,
            'loading_street' => 'Φόρτωσης',
            'loading_number' => '10',
            'loading_postcode' => '11111',
            'loading_city' => 'Αθήνα',
            'start_shipping_branch' => 0,
            'delivery_street' => 'Παράδοσης',
            'delivery_number' => '20',
            'delivery_postcode' => '22222',
            'delivery_city' => 'Θεσσαλονίκη',
            'complete_shipping_branch' => 0,
            'recipient_name' => 'Παραλήπτης ΑΕ',
            'recipient_afm' => '123456789',
            'local_status' => 'draft',
        ], $overrides));

        DeliveryNoteLine::create([
            'company_id' => $this->tenant->id,
            'delivery_note_id' => $note->id,
            'qty' => 3,
            'measurement_unit' => 1,
            'product_descr' => 'Κιβώτια',
        ]);

        return $note->fresh('lines');
    }

    public function test_build_assembles_delivery_header_and_value_less_line(): void
    {
        $note = $this->makeNote();

        $aade = (new DeliveryNoteSubmitter($this->tenant))->buildAadeDeliveryNote($note);

        $this->assertInstanceOf(AadeInvoice::class, $aade);

        $header = $aade->getInvoiceHeader();
        // AADE FORBIDS <isDeliveryNote> and <currency> for a 9.x type ([205],
        // sandbox-proven 2026-06-03) — the 9.x type already marks it a δελτίο.
        $this->assertNotTrue($header->getIsDeliveryNote());
        $this->assertNull($header->getCurrency());
        $this->assertSame(8, $header->getMovePurpose()->value);
        $this->assertSame('9.3', $header->getInvoiceType()->value);

        // Issuer + counterpart carry full identification (name + address) —
        // mandatory for 9.x ([204]), unlike the monetary-invoice GR rule.
        $this->assertSame('Delivery test', $aade->getIssuer()->getName());
        $this->assertNotNull($aade->getIssuer()->getAddress());
        $this->assertSame('Παραλήπτης ΑΕ', $aade->getCounterpart()->getName());
        $this->assertSame('Παράδοσης', $aade->getCounterpart()->getAddress()->getStreet());

        // OtherDeliveryNoteHeader carries both addresses.
        $odh = $header->getOtherDeliveryNoteHeader();
        $this->assertNotNull($odh);
        $this->assertSame('Φόρτωσης', $odh->getLoadingAddress()->getStreet());
        $this->assertSame('Παράδοσης', $odh->getDeliveryAddress()->getStreet());

        // Exactly one value-less line: quantity present, zero net/vat, category 8.
        $details = $aade->getInvoiceDetails();
        $this->assertCount(1, $details);
        $line = $details[0];
        $this->assertSame(3.0, (float) $line->getQuantity());
        $this->assertSame(0.0, (float) $line->getNetValue());
        $this->assertSame(0.0, (float) $line->getVatAmount());
        // vatCategory 8 = Εγγραφές χωρίς ΦΠΑ (no VAT).
        $this->assertSame('8', (string) ($line->getVatCategory()->value ?? $line->getVatCategory()));
    }

    public function test_preview_xml_contains_delivery_markers(): void
    {
        $note = $this->makeNote();

        $xml = (new DeliveryNoteSubmitter($this->tenant))->previewXml($note);

        $this->assertNotEmpty($xml);
        $this->assertStringContainsString('<invoiceType>9.3</invoiceType>', $xml);
        // AADE FORBIDS these for a 9.x type ([205]/[214], sandbox-proven 2026-06-03).
        $this->assertStringNotContainsString('<isDeliveryNote>', $xml);
        $this->assertStringNotContainsString('<currency>', $xml);
        $this->assertStringNotContainsString('<thirdPartyCollection>', $xml); // only sent when true
        $this->assertStringContainsString('<movePurpose>8</movePurpose>', $xml);
        $this->assertStringContainsString('<otherDeliveryNoteHeader>', $xml);
        $this->assertStringContainsString('<loadingAddress>', $xml);
        $this->assertStringContainsString('<deliveryAddress>', $xml);
        $this->assertStringContainsString('<vatCategory>8</vatCategory>', $xml);
        // Full issuer + counterpart identification is mandatory for 9.x ([204]).
        $this->assertStringContainsString('<name>Delivery test</name>', $xml);   // issuer
        $this->assertStringContainsString('<name>Παραλήπτης ΑΕ</name>', $xml);    // counterpart
        // «Χαρακτηρισμός Συναλλαγών 3 = Διακίνηση» — mandatory per Α.1123/2024 §5.2.2.
        $this->assertStringContainsString('category3', $xml);
        // Value-less: no payment methods on a delivery note.
        $this->assertStringNotContainsString('<paymentMethods>', $xml);
    }

    public function test_endodiakinisi_recipient_is_nine_zeros(): void
    {
        // No recipient (own-branch move) → recipient ΑΦΜ = 000000000 per the law,
        // never an omitted counterpart.
        $note = $this->makeNote(['customer_id' => null, 'recipient_afm' => null, 'recipient_name' => null]);

        $xml = (new DeliveryNoteSubmitter($this->tenant))->previewXml($note);

        $this->assertStringContainsString('<counterpart>', $xml);
        $this->assertStringContainsString('000000000', $xml);
    }

    public function test_other_move_purpose_title_required_for_purpose_19(): void
    {
        $note = $this->makeNote(['move_purpose' => 19, 'other_move_purpose_title' => null]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/other_move_purpose_title/');

        (new DeliveryNoteSubmitter($this->tenant))->previewXml($note);
    }

    public function test_blocked_move_purpose_is_rejected(): void
    {
        // §8.14: purpose 18 (Διακίνηση Παγίων) is no longer transmittable.
        $note = $this->makeNote(['move_purpose' => 18]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/no longer/');

        (new DeliveryNoteSubmitter($this->tenant))->previewXml($note);
    }

    public function test_draft_without_aa_number_throws(): void
    {
        $note = $this->makeNote(['code' => 0, 'invcode' => 'DA0']);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/no ΑΑ number/');

        (new DeliveryNoteSubmitter($this->tenant))->previewXml($note);
    }

    public function test_blank_delivery_address_throws_instead_of_filing_placeholder(): void
    {
        // A blank mandatory address must hard-fail, not file '00000'/'Άγνωστη'
        // into a legal e-transport record.
        $note = $this->makeNote(['delivery_city' => '']);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/διεύθυνση παράδοσης/');

        (new DeliveryNoteSubmitter($this->tenant))->previewXml($note);
    }

    public function test_submit_persists_mark_qr_and_delivery_mark_row(): void
    {
        $note = $this->makeNote();

        $mock = new MockHandler([
            new GuzzleResponse(200, [], $this->successResponseXml()),
        ]);

        $mark = (new DeliveryNoteSubmitter($this->tenant, $mock))->submit($note);

        $this->assertInstanceOf(DeliveryMark::class, $mark);
        $this->assertSame('INSERT', $mark->mydata_action);
        $this->assertSame('480301204040191', $mark->mark);
        $this->assertStringContainsString('TimologioQR', (string) $mark->invoice_url);
        $this->assertStringContainsString('<invoiceType>9.3</invoiceType>', (string) $mark->request);

        // delivery_marks audit row exists.
        $this->assertDatabaseHas('delivery_marks', [
            'delivery_note_id' => $note->id,
            'mark' => '480301204040191',
            'mydata_action' => 'INSERT',
        ]);

        // The note's guarded cache columns reflect the filing.
        $fresh = $note->fresh();
        $this->assertTrue((bool) $fresh->mydata_sent);
        $this->assertSame('VALID', $fresh->mydata_state);
        $this->assertSame('480301204040191', $fresh->mydata_mark);
        $this->assertSame('registered', $fresh->delivery_state);
        $this->assertSame('active', $fresh->local_status);
        $this->assertStringContainsString('TimologioQR', (string) $fresh->mydata_url);
    }

    public function test_submit_refuses_already_filed_note(): void
    {
        $note = $this->makeNote();
        $note->forceFill(['mydata_state' => 'VALID', 'mydata_mark' => '480301204040191'])->save();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/already filed at myDATA/');

        (new DeliveryNoteSubmitter($this->tenant))->submit($note->fresh());
    }

    /** Mirrors firebed's stubs/send-invoices-single-response.xml. */
    private function successResponseXml(): string
    {
        return <<<'XML'
<?xml version="1.0" encoding="utf-8"?>
<ResponseDoc xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance" xmlns:xsd="http://www.w3.org/2001/XMLSchema">
    <response>
        <index>1</index>
        <invoiceUid>6F825B9B74717280D1F9A38252D0B65604C6D162</invoiceUid>
        <invoiceMark>480301204040191</invoiceMark>
        <qrUrl>https://mydataapidev.aade.gr/TimologioQR/QRInfo?q=testqr</qrUrl>
        <statusCode>Success</statusCode>
    </response>
</ResponseDoc>
XML;
    }
}
