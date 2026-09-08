<?php

namespace Tests\Feature\Invoice;

use App\Models\Company;
use App\Models\Invoice;
use App\Models\InvoiceLine;
use App\Models\InvoiceType;
use App\Services\InvoicePdfRenderer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * DOC-4 (AUDIT): the issuer header must carry the ΓΕΜΗ number (ν.4919/2022 αρ.22)
 * and the primary activity (ΚΑΔ) when configured.
 */
class InvoicePdfIssuerHeaderTest extends TestCase
{
    use RefreshDatabase;

    private function invoiceFor(Company $tenant): Invoice
    {
        $type = InvoiceType::create([
            'company_id' => $tenant->id, 'code' => 'TPY', 'name' => 'ΤΠΥ', 'invcount' => 1,
        ]);
        $inv = Invoice::create([
            'company_id' => $tenant->id, 'invoice_type_id' => $type->id, 'code' => 1,
            'invcode' => 'TPY1', 'issued_at' => now(), 'local_status' => 'active',
        ]);
        InvoiceLine::create([
            'company_id' => $tenant->id, 'invoice_id' => $inv->id,
            'qty' => 1, 'vat_percent' => 24, 'price_per_item' => 100,
        ]);

        return $inv->fresh();
    }

    public function test_prints_gemi_and_activity_when_set(): void
    {
        $tenant = Company::create([
            'name' => 'ΑΚΜΕ ΑΕ', 'slug' => 'ge-'.uniqid(), 'country_code' => 'GR',
            'einvoice_provider' => 'gr-mydata', 'mydata_mode' => 'off',
            'afm' => '800561849', 'tax_office' => 'ΦΑΕ', 'kad_primary' => '62010', 'gemi' => '123456789000',
        ]);

        $html = app(InvoicePdfRenderer::class)->renderHtml($this->invoiceFor($tenant));

        $this->assertStringContainsString('ΓΕΜΗ: 123456789000', $html);
        $this->assertStringContainsString('62010', $html);
    }

    public function test_omits_gemi_line_when_not_set(): void
    {
        $tenant = Company::create([
            'name' => 'Χωρίς ΓΕΜΗ', 'slug' => 'ng-'.uniqid(), 'country_code' => 'GR',
            'einvoice_provider' => 'gr-mydata', 'mydata_mode' => 'off', 'afm' => '800561849',
        ]);

        $html = app(InvoicePdfRenderer::class)->renderHtml($this->invoiceFor($tenant));

        $this->assertStringNotContainsString('ΓΕΜΗ:', $html);
    }
}
