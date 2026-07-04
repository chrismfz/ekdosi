<?php

namespace Tests\Feature\Invoice;

use App\Models\Company;
use App\Models\Invoice;
use App\Models\InvoiceLine;
use App\Models\InvoiceType;
use App\Services\InvoicePdfRenderer;
use App\Support\Pdf\InvoiceBannerState;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * DOC-2/DOC-3 (AUDIT): the PDF banner is a function of local_status ×
 * mydata_state × provider — not mydata_state alone. The three hazards this
 * locks out:
 *   - locally-cancelled + VALID printing as a clean certified invoice,
 *   - locally-cancelled + null printing «ΠΡΟΧΕΙΡΟ» instead of ΑΚΥΡΩΘΕΝ,
 *   - issued invoices of non-myDATA tenants carrying a DRAFT banner forever.
 */
class InvoicePdfBannerStateTest extends TestCase
{
    use RefreshDatabase;

    private function tenant(array $overrides = []): Company
    {
        return Company::create(array_merge([
            'name' => 'Banner', 'slug' => 'bn-'.uniqid(),
            'country_code' => 'GR', 'einvoice_provider' => 'gr-mydata', 'mydata_mode' => 'sandbox',
        ], $overrides));
    }

    private function invoice(Company $tenant, array $overrides = []): Invoice
    {
        $type = InvoiceType::create([
            'company_id' => $tenant->id, 'code' => 'TPY', 'name' => 'ΤΠΥ', 'invcount' => 1,
        ]);

        $inv = Invoice::create(array_merge([
            'company_id' => $tenant->id,
            'invoice_type_id' => $type->id,
            'code' => 1,
            'invcode' => 'TPY9',
            'issued_at' => now(),
            'local_status' => 'active',
        ], array_diff_key($overrides, array_flip(['mydata_state', 'mydata_mark', 'mydata_url']))));

        // Mirror columns are non-fillable by design — forceFill like the submitter.
        $mirror = array_intersect_key($overrides, array_flip(['mydata_state', 'mydata_mark', 'mydata_url']));
        if ($mirror !== []) {
            $inv->forceFill($mirror)->save();
        }

        InvoiceLine::create([
            'company_id' => $tenant->id, 'invoice_id' => $inv->id,
            'qty' => 1, 'vat_percent' => 24, 'price_per_item' => 100,
        ]);

        return $inv->fresh();
    }

    private function html(Invoice $inv): string
    {
        return app(InvoicePdfRenderer::class)->renderHtml($inv);
    }

    public function test_locally_cancelled_but_aade_valid_prints_cancelled_banner_with_pending_note(): void
    {
        $inv = $this->invoice($this->tenant(), [
            'local_status' => 'cancelled',
            'mydata_state' => 'VALID',
            'mydata_mark' => '400000000000001',
            'mydata_url' => 'https://mydata.aade.gr/view/abc',
        ]);

        $state = InvoiceBannerState::for($inv);
        $this->assertSame(['kind' => 'cancelled', 'note' => 'cancel_pending_mydata'], $state);

        $html = $this->html($inv);
        $this->assertStringContainsString('ΑΚΥΡΩΘΕΝ ΠΑΡΑΣΤΑΤΙΚΟ', $html);
        $this->assertStringContainsString('Εκκρεμεί ακύρωση στο myDATA', $html);
        // DOC-7: no «Πιστοποιημένο» claims on a cancelled document.
        $this->assertStringNotContainsString('Πιστοποιημένο', $html);
    }

    public function test_locally_cancelled_never_filed_prints_cancelled_not_draft(): void
    {
        $inv = $this->invoice($this->tenant(), ['local_status' => 'cancelled']);

        $html = $this->html($inv);
        $this->assertStringContainsString('ΑΚΥΡΩΘΕΝ ΠΑΡΑΣΤΑΤΙΚΟ', $html);
        $this->assertStringNotContainsString('ΠΡΟΧΕΙΡΟ', $html);
    }

    public function test_draft_prints_provider_agnostic_draft_banner(): void
    {
        $inv = $this->invoice($this->tenant(), ['local_status' => 'draft']);

        $html = $this->html($inv);
        $this->assertStringContainsString('ΠΡΟΧΕΙΡΟ — ΔΕΝ ΕΧΕΙ ΕΚΔΟΘΕΙ', $html);
    }

    public function test_issued_unfiled_on_mydata_tenant_prints_pending_submission_banner(): void
    {
        $inv = $this->invoice($this->tenant()); // active, state null, sandbox mode

        $html = $this->html($inv);
        $this->assertStringContainsString('ΕΚΔΟΘΕΝ — ΕΚΚΡΕΜΕΙ ΥΠΟΒΟΛΗ ΣΤΟ myDATA', $html);
        $this->assertStringNotContainsString('ΠΡΟΧΕΙΡΟ', $html);
    }

    public function test_issued_invoice_of_non_filing_tenant_carries_no_banner(): void
    {
        // DOC-3: a non-filing tenant — a legally issued invoice must NOT carry
        // a DRAFT / pending-submission banner (or any myDATA reference) forever.
        // Covers every non-filing shape, including gr-provider in mode=off
        // (the case a hand-rolled filesToAade() copy previously got wrong).
        $configs = [
            ['einvoice_provider' => 'none'],
            ['einvoice_provider' => 'gr-mydata', 'mydata_mode' => 'off'],
            ['einvoice_provider' => 'gr-provider', 'mydata_mode' => 'off', 'einvoice_provider_mode' => 'off'],
        ];
        foreach ($configs as $cfg) {
            $inv = $this->invoice($this->tenant($cfg));

            $html = $this->html($inv);
            $this->assertStringNotContainsString('ΠΡΟΧΕΙΡΟ', $html);
            $this->assertStringNotContainsString('ΕΚΚΡΕΜΕΙ ΥΠΟΒΟΛΗ', $html);
            $this->assertStringNotContainsString('<div class="banner banner-draft">', $html);
        }
    }

    public function test_issued_unfiled_on_live_provider_tenant_prints_pending_banner(): void
    {
        // Symmetric to the myDATA-tenant case: a live ΥΠΑΗΕΣ provider tenant
        // (mode ≠ off) DOES file, so an issued-but-unfiled invoice warns.
        $inv = $this->invoice($this->tenant([
            'einvoice_provider' => 'gr-provider',
            'mydata_mode' => 'off',
            'einvoice_provider_mode' => 'production',
        ]));

        $html = $this->html($inv);
        $this->assertStringContainsString('ΕΚΚΡΕΜΕΙ ΥΠΟΒΟΛΗ', $html);
    }

    public function test_valid_active_invoice_prints_certified_and_no_warning_banner(): void
    {
        $inv = $this->invoice($this->tenant(), [
            'mydata_state' => 'VALID',
            'mydata_mark' => '400000000000002',
            'mydata_url' => 'https://mydata.aade.gr/view/xyz',
        ]);

        $html = $this->html($inv);
        $this->assertStringContainsString('Πιστοποιημένο', $html);
        $this->assertStringNotContainsString('ΠΡΟΧΕΙΡΟ', $html);
        $this->assertStringNotContainsString('ΑΚΥΡΩΘΕΝ', $html);
    }

    public function test_cancelled_at_aade_prints_cancelled_without_certified_footer(): void
    {
        $inv = $this->invoice($this->tenant(), [
            'mydata_state' => 'CANCELLED',
            'mydata_mark' => '400000000000003',
            'mydata_url' => 'https://mydata.aade.gr/view/dead',
        ]);

        $html = $this->html($inv);
        $this->assertStringContainsString('ΑΚΥΡΩΘΕΝ ΠΑΡΑΣΤΑΤΙΚΟ', $html);
        // DOC-7: QR/MARK may stay (scanning shows the true AADE state), but
        // the «Πιστοποιημένο στη myDATA — επαληθεύστε» claim must not print.
        $this->assertStringNotContainsString('Πιστοποιημένο στη myDATA', $html);
    }
}
