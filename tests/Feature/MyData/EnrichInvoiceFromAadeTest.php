<?php

namespace Tests\Feature\MyData;

use App\Models\Company;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\InvoiceType;
use App\Models\MyDataMark;
use App\Services\MyData\EnrichInvoiceFromAade;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * EnrichInvoiceFromAade: QR is always (re)stamped, other fields are
 * fill-blanks only (never overwrite a populated value on a filed doc), and a
 * field-by-field comparison (✓/⚠) is returned for the popup.
 */
class EnrichInvoiceFromAadeTest extends TestCase
{
    use RefreshDatabase;

    private Company $tenant;

    private Invoice $invoice;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Company::create([
            'name' => 'Enrich test', 'slug' => 'enrich-'.uniqid(),
            'country_code' => 'GR', 'einvoice_provider' => 'gr-mydata', 'afm' => '800561849',
        ]);
        $type = InvoiceType::create([
            'company_id' => $this->tenant->id, 'code' => 'ΤΙΜ', 'name' => 'Τιμολόγιο', 'invcount' => 1,
        ]);
        $customer = Customer::create([
            'company_id' => $this->tenant->id, 'name' => 'Πελάτης', 'afm' => '123456789',
        ]);

        // An imported invoice: has a MARK, but NO QR URL and a blank type/company_name.
        $this->invoice = Invoice::create([
            'company_id' => $this->tenant->id, 'invcode' => 'ΤΙΜ385', 'code' => 385,
            'invoice_type_id' => $type->id, 'customer_id' => $customer->id, 'issued_at' => now(),
            'header_discount_percent' => 0, 'net_total' => 134.20, 'gross_total' => 166.41,
        ]);
        $this->invoice->forceFill(['mydata_mark' => '400013744877362', 'mydata_state' => 'VALID'])->save();

        MyDataMark::create([
            'company_id' => $this->tenant->id, 'invoice_id' => $this->invoice->id,
            'mark' => '400013744877362', 'mydata_action' => 'INSERT',
        ]);
    }

    /** @return array<string,mixed> */
    private function aadeDoc(array $overrides = []): array
    {
        return array_merge([
            'qrCodeUrl' => 'https://mydatapi.aade.gr/myDATA/TimologioQR/QRInfo?q=ABC123',
            'counterpartName' => 'ΑΑΔΕ ΕΠΩΝΥΜΙΑ ΑΕ',
            'counterpartVat' => '123456789',
            'invoiceType' => '1.1',
            'state' => 'VALID',
            'invcode' => 'ΤΙΜ 385',
            'netTotal' => 134.20,
            'grossTotal' => 166.41,
            'lines' => [['x' => 1]],
        ], $overrides);
    }

    public function test_stamps_qr_and_fills_blanks_then_pdf_can_render_qr(): void
    {
        $report = app(EnrichInvoiceFromAade::class)->enrich($this->invoice->fresh(), $this->aadeDoc());

        $this->assertTrue($report['stamped_qr']);
        $this->assertContains('τύπος myDATA', $report['filled']);
        $this->assertContains('επωνυμία αντισυμβαλλόμενου', $report['filled']);

        $fresh = $this->invoice->fresh();
        $this->assertSame('https://mydatapi.aade.gr/myDATA/TimologioQR/QRInfo?q=ABC123', $fresh->mydata_url);
        $this->assertSame('1.1', $fresh->mydata_type);
        $this->assertSame('ΑΑΔΕ ΕΠΩΝΥΜΙΑ ΑΕ', $fresh->company_name);

        // The audit mark row's QR was synced too.
        $this->assertSame(
            'https://mydatapi.aade.gr/myDATA/TimologioQR/QRInfo?q=ABC123',
            MyDataMark::where('invoice_id', $this->invoice->id)->value('invoice_url')
        );
    }

    public function test_never_overwrites_a_populated_value_but_flags_the_difference(): void
    {
        // Operator already corrected the ΑΦΜ locally; AADE has a different one.
        $this->invoice->forceFill(['vat_no' => '999999999'])->save();

        $report = app(EnrichInvoiceFromAade::class)->enrich($this->invoice->fresh(), $this->aadeDoc());

        // Populated value untouched…
        $this->assertSame('999999999', $this->invoice->fresh()->vat_no);
        $this->assertNotContains('ΑΦΜ αντισυμβαλλόμενου', $report['filled']);

        // …and surfaced as a difference in the comparison.
        $vatRow = collect($report['comparison'])->firstWhere('label', 'ΑΦΜ αντισυμβαλλόμενου');
        $this->assertNotNull($vatRow);
        $this->assertFalse($vatRow['match']);
        $this->assertSame('999999999', $vatRow['local']);
        $this->assertSame('123456789', $vatRow['aade']);
    }

    public function test_series_aa_matches_despite_aade_space_separator(): void
    {
        // Local invcode is «ΤΙΜ385» (code.aa, no space); AADE joins with a space
        // («ΤΙΜ 385»). Whitespace-normalised comparison must agree, else every
        // enrich cries a phantom Σειρά/ΑΑ difference.
        $report = app(EnrichInvoiceFromAade::class)->enrich($this->invoice->fresh(), $this->aadeDoc());

        $row = collect($report['comparison'])->firstWhere('label', 'Σειρά/ΑΑ');
        $this->assertNotNull($row);
        $this->assertTrue($row['match'], '«ΤΙΜ385» vs «ΤΙΜ 385» normalised → match');
    }

    public function test_blank_local_field_is_filled_not_flagged_as_difference(): void
    {
        // vat_no is blank locally; AADE has one → it's a fill, NOT a ⚠ conflict.
        $report = app(EnrichInvoiceFromAade::class)->enrich($this->invoice->fresh(), $this->aadeDoc());

        $this->assertContains('ΑΦΜ αντισυμβαλλόμενου', $report['filled']);
        $this->assertSame('123456789', $this->invoice->fresh()->vat_no);
        $this->assertNull(
            collect($report['comparison'])->firstWhere('label', 'ΑΦΜ αντισυμβαλλόμενου'),
            'a blank-then-filled field is not reported as a difference'
        );
    }

    public function test_does_not_stamp_qr_when_aade_doc_is_cancelled(): void
    {
        $report = app(EnrichInvoiceFromAade::class)->enrich(
            $this->invoice->fresh(),
            $this->aadeDoc(['state' => 'CANCELLED'])
        );

        $this->assertFalse($report['stamped_qr']);
        $this->assertTrue($report['qr_skipped_cancelled']);
        $this->assertNull($this->invoice->fresh()->mydata_url, 'no QR onto a cancelled-at-AADE doc');

        // The state divergence (local VALID vs AADE CANCELLED) is surfaced.
        $stateRow = collect($report['comparison'])->firstWhere('label', 'Κατάσταση');
        $this->assertNotNull($stateRow);
        $this->assertFalse($stateRow['match']);
    }

    public function test_comparison_marks_agreement_and_money_mismatch(): void
    {
        $report = app(EnrichInvoiceFromAade::class)->enrich(
            $this->invoice->fresh(),
            $this->aadeDoc(['grossTotal' => 200.00]) // AADE gross differs
        );

        $gross = collect($report['comparison'])->firstWhere('label', 'Σύνολο');
        $this->assertFalse($gross['match'], 'gross mismatch flagged');

        $net = collect($report['comparison'])->firstWhere('label', 'Καθαρή αξία');
        $this->assertTrue($net['match'], 'net agrees');
    }
}
