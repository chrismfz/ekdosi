<?php

namespace Tests\Feature\Invoice;

use App\Models\Company;
use App\Models\Invoice;
use App\Models\InvoiceLine;
use App\Models\InvoiceType;
use App\Models\MyDataMark;
use App\Services\InvoicePdfRenderer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * PROV-003 / A.1112/2025: a document filed through a ΥΠΑΗΕΣ provider must PRINT
 * the provider evidence — provider name/site/licence, the MARK, the document UID
 * and the authentication code. A direct-myDATA (or unfiled) document must NOT
 * carry that block. Provider identity is resolved from config by the filing
 * mark's provider_key; MARK/UID/auth-code come from the mark itself.
 */
class InvoicePdfProviderEvidenceTest extends TestCase
{
    use RefreshDatabase;

    private function tenant(): Company
    {
        return Company::create([
            'name' => 'Prov', 'slug' => 'prov-'.uniqid(),
            'country_code' => 'GR', 'einvoice_provider' => 'gr-provider',
            'einvoice_provider_key' => 'invosign', 'einvoice_provider_mode' => 'production',
            'afm' => '800561849',
        ]);
    }

    private function invoice(Company $tenant): Invoice
    {
        $type = InvoiceType::create([
            'company_id' => $tenant->id, 'code' => 'TPY', 'name' => 'ΤΠΥ',
            'invcount' => 1, 'mydata_type' => '2.1',
        ]);
        $invoice = Invoice::create([
            'company_id' => $tenant->id, 'invoice_type_id' => $type->id,
            'code' => 1, 'invcode' => 'TPY100', 'issued_at' => now(),
            'local_status' => 'active', 'country' => 'GR',
        ]);
        $invoice->forceFill(['mydata_state' => 'VALID', 'mydata_mark' => '400001999999999'])->save();
        InvoiceLine::create([
            'company_id' => $tenant->id, 'invoice_id' => $invoice->id,
            'qty' => 1, 'vat_percent' => 24, 'net_price' => 100, 'gross_price' => 124,
        ]);

        return $invoice;
    }

    private function providerMark(Invoice $invoice): void
    {
        MyDataMark::create([
            'company_id' => $invoice->company_id, 'invoice_id' => $invoice->id,
            'mark' => '400001999999999', 'mydata_action' => 'PROVIDER_INSERT',
            'provider_key' => 'invosign',
            'authentication_code' => 'AUTHCODE-DEADBEEF-1234',
            'uid' => 'UID-CAFEBABE-5678',
            'invoice_url' => 'https://invosign.gr/viewinvoice.php?q=x',
            'mark_date' => now()->toDateString(), 'mark_time' => now()->toTimeString(),
        ]);
    }

    private function directMark(Invoice $invoice): void
    {
        MyDataMark::create([
            'company_id' => $invoice->company_id, 'invoice_id' => $invoice->id,
            'mark' => '400001999999999', 'mydata_action' => 'INSERT',
            'mark_date' => now()->toDateString(), 'mark_time' => now()->toTimeString(),
        ]);
    }

    public function test_provider_filed_invoice_prints_the_full_evidence_block(): void
    {
        $invoice = $this->invoice($this->tenant());
        $this->providerMark($invoice);

        $html = app(InvoicePdfRenderer::class)->renderHtml($invoice);

        $this->assertStringContainsString('Εκδόθηκε μέσω παρόχου', $html); // the rendered block heading
        $this->assertStringContainsString('iNVO Sign', $html);          // commercial name (config)
        $this->assertStringContainsString('030', $html);                // AADE provider code
        $this->assertStringContainsString('2025_05_130GVSolutions', $html); // licence no.
        $this->assertStringContainsString('UID-CAFEBABE-5678', $html);  // the persisted UID
        $this->assertStringContainsString('AUTHCODE-DEADBEEF-1234', $html); // authentication code
    }

    public function test_provider_licence_is_frozen_per_document_via_the_snapshot(): void
    {
        // PROV-003 (b): the mark carries the identity IN FORCE at issue. Even after
        // the config licence rotates, reprinting an old invoice must show its
        // ORIGINAL licence — not the current one.
        $invoice = $this->invoice($this->tenant());
        MyDataMark::create([
            'company_id' => $invoice->company_id, 'invoice_id' => $invoice->id,
            'mark' => '400001999999999', 'mydata_action' => 'PROVIDER_INSERT',
            'provider_key' => 'invosign',
            'provider_identity' => [
                'key' => 'invosign',
                'commercial_name' => 'iNVO Sign',
                'legal_name' => 'GV Solutions',
                'site' => 'invosign.gr',
                'aade_code' => '030',
                'licence_no' => 'FROZEN_LICENCE_V1_ORIGINAL',
            ],
            'authentication_code' => 'A', 'uid' => 'U',
            'mark_date' => now()->toDateString(), 'mark_time' => now()->toTimeString(),
        ]);

        // Rotate the config licence AFTER the document was issued.
        config(['ekdosi.einvoice.provider_identity.invosign.licence_no' => 'ROTATED_LICENCE_V2_NEW']);

        $html = app(InvoicePdfRenderer::class)->renderHtml($invoice);

        $this->assertStringContainsString('FROZEN_LICENCE_V1_ORIGINAL', $html);       // snapshot wins
        $this->assertStringNotContainsString('ROTATED_LICENCE_V2_NEW', $html);        // config rotation ignored
        $this->assertStringNotContainsString('2025_05_130GVSolutions', $html);        // even the real config licence is overridden
    }

    public function test_without_a_snapshot_the_block_falls_back_to_config(): void
    {
        // Legacy / pre-migration provider marks carry no snapshot — the block must
        // still render, from the current config identity.
        $invoice = $this->invoice($this->tenant());
        $this->providerMark($invoice); // sets no provider_identity

        $html = app(InvoicePdfRenderer::class)->renderHtml($invoice);

        $this->assertStringContainsString('2025_05_130GVSolutions', $html); // config licence
        $this->assertStringContainsString('iNVO Sign', $html);
    }

    public function test_direct_mydata_invoice_has_no_provider_block(): void
    {
        $invoice = $this->invoice($this->tenant());
        $this->directMark($invoice);

        $html = app(InvoicePdfRenderer::class)->renderHtml($invoice);

        // The rendered block (heading, provider name, evidence) is absent — the
        // static CSS comment mentioning ΥΠΑΗΕΣ is not the block, so we assert on
        // content that ONLY appears when the block renders.
        $this->assertStringNotContainsString('iNVO Sign', $html);
        $this->assertStringNotContainsString('Εκδόθηκε μέσω παρόχου', $html);
    }

    public function test_provider_block_is_tied_to_the_current_filing_mark(): void
    {
        // Filed via provider (mark A), then re-filed directly to myDATA — the live
        // mirror MARK is now the DIRECT one (B). The provider block must NOT print
        // the stale provider MARK A, or the PDF would carry two conflicting MARKs
        // and falsely assert provider issuance.
        $invoice = $this->invoice($this->tenant());
        $this->providerMark($invoice);                    // PROVIDER_INSERT mark A = 400001999999999
        $invoice->forceFill(['mydata_mark' => '400002222222222'])->save(); // now filed direct → B

        $html = app(InvoicePdfRenderer::class)->renderHtml($invoice);

        $this->assertStringNotContainsString('Εκδόθηκε μέσω παρόχου', $html);
        $this->assertStringNotContainsString('iNVO Sign', $html);
    }

    public function test_cancelled_provider_invoice_drops_the_block(): void
    {
        $invoice = $this->invoice($this->tenant());
        $this->providerMark($invoice);
        // AADE-cancelled → the evidence of a live certified document must not show.
        $invoice->forceFill(['mydata_state' => 'CANCELLED', 'local_status' => 'cancelled'])->save();

        $html = app(InvoicePdfRenderer::class)->renderHtml($invoice);

        $this->assertStringNotContainsString('iNVO Sign', $html);
    }
}
