<?php

namespace Tests\Feature\Delivery;

use App\Models\Company;
use App\Models\Customer;
use App\Models\DeliveryNote;
use App\Models\DeliveryNoteLine;
use App\Models\InvoiceType;
use App\Models\MyDataMark;
use App\Services\Delivery\DeliveryNotePdf;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * PDF-COMPACT (Δελτίο Αποστολής): the ΔΑ PDF got the same treatment as the invoice
 * PDF — a compact one-page layout, and the «Σελίδα X από Y» pager drawn on the
 * DomPDF canvas (DomPDF 3.x resolves counter(pages) to 0 in a fixed footer, so the
 * old template printed «Σελίδα 1 από 0»). This locks: a typical filed ΔΑ fits one
 * A4 page, the pager is NOT the broken footer counter / inline text/php, and a long
 * ΔΑ still paginates.
 */
class DeliveryNotePdfCompactPaginationTest extends TestCase
{
    use RefreshDatabase;

    private string $logoPath = '';

    protected function tearDown(): void
    {
        if ($this->logoPath !== '') {
            Storage::disk('public')->delete($this->logoPath);
        }
        parent::tearDown();
    }

    /** Count PDF pages from raw bytes: each page is a `/Type /Page` object (the page
     *  TREE is `/Type /Pages` — excluded by the negative lookahead). */
    private function pageCount(string $pdf): int
    {
        return preg_match_all('#/Type\s*/Page(?![s])#', $pdf);
    }

    private function filedNote(int $lines = 4): DeliveryNote
    {
        $img = imagecreatetruecolor(320, 84);
        imagefill($img, 0, 0, imagecolorallocate($img, 255, 255, 255));
        imagefilledrectangle($img, 0, 24, 60, 60, imagecolorallocate($img, 37, 99, 235));
        ob_start();
        imagepng($img);
        $png = (string) ob_get_clean();
        $this->logoPath = 'logos/da-test-'.uniqid().'.png';
        Storage::disk('public')->put($this->logoPath, $png);

        $tenant = Company::create([
            'name' => 'MyIP Networks OE', 'slug' => 'datest-'.uniqid(),
            'country_code' => 'GR', 'einvoice_provider' => 'gr-mydata', 'mydata_mode' => 'sandbox',
            'afm' => '800561849', 'tax_office' => 'Ξάνθης', 'address' => 'Κανάρη 5', 'city' => 'Ξάνθη',
            'postcode' => '67100', 'gemi' => '129451646000', 'kad_primary' => 'Υπηρεσίες Διαδικτύου',
            'phone' => '215 215 4722', 'email' => 'support@myip.gr', 'logo_path' => $this->logoPath,
        ]);
        $type = InvoiceType::create(['company_id' => $tenant->id, 'code' => 'DA', 'name' => 'Δελτίο Αποστολής', 'invcount' => 105, 'mydata_type' => '9.3']);
        $recipient = Customer::create(['company_id' => $tenant->id, 'name' => 'ΝΕΧΟΝ ΠΛΗΡΟΦΟΡΙΚΗ ΟΕ', 'afm' => '321321516']);

        $note = DeliveryNote::create([
            'company_id' => $tenant->id, 'invcode' => 'DA105', 'code' => 105,
            'delivery_type_id' => $type->id, 'customer_id' => $recipient->id, 'issued_at' => now(),
            'mydata_type' => '9.3', 'move_purpose' => 1, 'dispatch_at' => now(), 'vehicle_number' => 'ΝΒΤ4521',
            'transport_type' => 3, 'loading_street' => 'Έδρα μας', 'loading_postcode' => '67100', 'loading_city' => 'Ξάνθη',
            'delivery_street' => 'Σταύρου', 'delivery_number' => '4', 'delivery_postcode' => '62121', 'delivery_city' => 'Σέρρες',
            'recipient_name' => 'ΝΕΧΟΝ ΠΛΗΡΟΦΟΡΙΚΗ ΟΕ', 'recipient_afm' => '321321516', 'recipient_country' => 'GR',
            'local_status' => 'active',
        ]);
        $note->forceFill([
            'mydata_state' => 'VALID', 'mydata_mark' => '400001393340274',
            'mydata_url' => 'https://mydata.aade.gr/timologio/ShowInvoiceInfo?ProviderMark=400001393340274&EntityVatNumber=800561849',
        ])->save();

        for ($i = 0; $i < $lines; $i++) {
            DeliveryNoteLine::create([
                'company_id' => $tenant->id, 'delivery_note_id' => $note->id,
                'qty' => 2, 'measurement_unit' => 1, 'product_descr' => 'ETH SFP CISCO 1GbE — γραμμή '.($i + 1),
            ]);
        }
        MyDataMark::create([
            'company_id' => $tenant->id, 'delivery_note_id' => $note->id, 'mark' => '400001393340274',
            'mydata_action' => 'INSERT', 'mark_date' => now()->toDateString(), 'mark_time' => now()->toTimeString(),
        ]);

        return $note->fresh();
    }

    public function test_a_typical_filed_delivery_note_fits_one_page(): void
    {
        $pdf = app(DeliveryNotePdf::class)->render($this->filedNote(4));

        $this->assertSame(1, $this->pageCount($pdf), 'a typical filed ΔΑ must fit on ONE A4 page');
    }

    public function test_template_has_no_broken_footer_counter_or_inline_php(): void
    {
        $tpl = (string) file_get_contents(resource_path('views/delivery-notes/pdf.blade.php'));

        // The «από 0» footer counter is gone…
        $this->assertStringNotContainsString('pager-total', $tpl);
        $this->assertStringNotContainsString('content: counter(pages)', $tpl);
        // …and the pager is drawn from the renderer, never an inline text/php script.
        $this->assertStringNotContainsString('text/php', $tpl);
    }

    public function test_a_long_delivery_note_still_paginates(): void
    {
        $pdf = app(DeliveryNotePdf::class)->render($this->filedNote(30));

        $this->assertGreaterThanOrEqual(2, $this->pageCount($pdf));
    }
}
