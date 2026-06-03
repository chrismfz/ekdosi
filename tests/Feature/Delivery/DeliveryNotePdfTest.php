<?php

namespace Tests\Feature\Delivery;

use App\Models\Company;
use App\Models\Customer;
use App\Models\DeliveryNote;
use App\Models\DeliveryNoteLine;
use App\Models\InvoiceType;
use App\Services\Delivery\DeliveryNotePdf;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * No-network coverage for DeliveryNotePdf (D2 part 4). Mirrors
 * QuotePdfEmailTest's byte assertion (%PDF header) and the
 * DeliveryNoteSubmitterTest tenant/type/note construction.
 *
 * The PDF bytes are deflate-compressed, so we can't grep the MARK/invcode out
 * of them directly — instead we render the Blade itself (the same view the
 * renderer feeds DomPDF) to assert the MARK / invcode / draft marker appear.
 */
class DeliveryNotePdfTest extends TestCase
{
    use RefreshDatabase;

    private Company $tenant;

    private InvoiceType $deliveryType;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Company::create([
            'name' => 'Delivery PDF test',
            'slug' => 'delpdf-'.uniqid(),
            'country_code' => 'GR',
            'einvoice_provider' => 'gr-mydata',
            'mydata_mode' => 'sandbox',
            'afm' => '800561849',
        ]);

        $this->deliveryType = InvoiceType::create([
            'company_id' => $this->tenant->id,
            'code' => 'DA',
            'name' => 'Δελτίο Αποστολής',
            'invcount' => 1,
            'mydata_type' => '9.3',
        ]);
    }

    private function makeNote(array $overrides = []): DeliveryNote
    {
        $recipient = Customer::create([
            'company_id' => $this->tenant->id,
            'name' => 'Παραλήπτης ΑΕ',
            'afm' => '123456789',
        ]);

        $note = DeliveryNote::create(array_merge([
            'company_id' => $this->tenant->id,
            'invcode' => 'DA1',
            'code' => 1,
            'delivery_type_id' => $this->deliveryType->id,
            'customer_id' => $recipient->id,
            'issued_at' => now(),
            'mydata_type' => '9.3',
            'move_purpose' => 8,                 // Ενδοδιακίνηση
            'dispatch_at' => now()->addHour(),
            'vehicle_number' => 'ΙΑΒ1234',
            'transport_type' => 1,
            'loading_street' => 'Φόρτωσης',
            'loading_number' => '10',
            'loading_postcode' => '11111',
            'loading_city' => 'Αθήνα',
            'delivery_street' => 'Παράδοσης',
            'delivery_number' => '20',
            'delivery_postcode' => '22222',
            'delivery_city' => 'Θεσσαλονίκη',
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

    /** Mark a note as filed (VALID) the same way the submitter does — forceFill. */
    private function fileNote(DeliveryNote $note, string $mark): DeliveryNote
    {
        $note->forceFill([
            'local_status' => 'active',
            'mydata_sent' => true,
            'mydata_state' => 'VALID',
            'mydata_mark' => $mark,
            'mydata_url' => 'https://mydata.aade.gr/check?mark='.$mark,
        ])->save();

        return $note->fresh('lines');
    }

    public function test_filed_note_renders_pdf_bytes(): void
    {
        $note = $this->fileNote($this->makeNote(), '400001234567890');

        $pdf = app(DeliveryNotePdf::class)->render($note);

        $this->assertNotEmpty($pdf);
        $this->assertStringStartsWith('%PDF', $pdf);
    }

    public function test_filed_note_html_contains_mark_and_invcode(): void
    {
        $mark = '400001234567890';
        $note = $this->fileNote($this->makeNote(), $mark);

        $html = view('delivery-notes.pdf', [
            'note' => $note,
            'tenant' => $note->company,
            'qrDataUri' => null,   // QR generation is exercised by the bytes test
            'logoDataUri' => null,
        ])->render();

        $this->assertStringContainsString($mark, $html);
        $this->assertStringContainsString('DA1', $html);
        // No draft banner on a filed note.
        $this->assertStringNotContainsString('ΜΗ ΔΙΑΒΙΒΑΣΜΕΝΟ', $html);
    }

    public function test_draft_note_shows_proxeiro_marker_and_no_qr(): void
    {
        $note = $this->makeNote(); // draft: mydata_state null, no mydata_url

        $pdf = app(DeliveryNotePdf::class)->render($note);
        $this->assertStringStartsWith('%PDF', $pdf);

        $html = view('delivery-notes.pdf', [
            'note' => $note,
            'tenant' => $note->company,
            'qrDataUri' => null,
            'logoDataUri' => null,
        ])->render();

        $this->assertStringContainsString('ΠΡΟΧΕΙΡΟ', $html);
        // Draft has no myDATA footer / QR image markup.
        $this->assertStringNotContainsString('myDATA QR', $html);
    }

    public function test_internal_movement_recipient_label(): void
    {
        $note = $this->makeNote([
            'recipient_name' => null,
            'recipient_afm' => '000000000',
            'customer_id' => null,
        ]);

        $html = view('delivery-notes.pdf', [
            'note' => $note,
            'tenant' => $note->company,
            'qrDataUri' => null,
            'logoDataUri' => null,
        ])->render();

        $this->assertStringContainsString('Ενδοδιακίνηση', $html);
    }
}
