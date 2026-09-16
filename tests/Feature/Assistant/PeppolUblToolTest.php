<?php

namespace Tests\Feature\Assistant;

use App\Models\Company;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\InvoiceLine;
use App\Models\InvoiceType;
use App\Models\User;
use App\Services\Assistant\Tools\PeppolUblTool;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Tests\TestCase;

/**
 * invoice_ubl — the read-only PEPPOL BIS 3.0 (EN 16931) preview tool. Dual-surface
 * (its MCP adapter is enforced by McpAssistantParityTest); here we exercise the
 * in-app tool logic, including the EL-vs-GR VAT subtlety.
 */
class PeppolUblToolTest extends TestCase
{
    use RefreshDatabase;

    private Company $tenant;

    protected function setUp(): void
    {
        parent::setUp();
        Gate::before(fn () => true);
        $this->tenant = Company::create([
            'name' => 'MyIP ΙΚΕ', 'slug' => 'ubl-'.uniqid(), 'country_code' => 'GR',
            'einvoice_provider' => 'gr-mydata', 'afm' => '800561849',
            'address' => 'Κηφισίας 1', 'city' => 'Αθήνα', 'postcode' => '11523',
        ]);
        $this->actingAs(User::create(['name' => 'Op', 'email' => 'op-'.uniqid().'@t.local', 'password' => bcrypt('x')]));
        Filament::setTenant($this->tenant);
    }

    private function invoice(): Invoice
    {
        $type = InvoiceType::create(['company_id' => $this->tenant->id, 'code' => 'TPY', 'name' => 'ΤΠΥ', 'invcount' => 1]);
        $customer = Customer::create([
            'company_id' => $this->tenant->id, 'type' => 'company', 'name' => 'Πελάτης ΑΕ',
            'afm' => '094512345', 'country' => 'GR', 'city' => 'Αθήνα', 'postcode' => '10563',
        ]);
        $invoice = Invoice::create([
            'company_id' => $this->tenant->id, 'customer_id' => $customer->id, 'invoice_type_id' => $type->id,
            'code' => 1001, 'invcode' => 'ΤΠΥ1001', 'issued_at' => now(), 'local_status' => 'active',
        ]);
        InvoiceLine::create([
            'company_id' => $this->tenant->id, 'invoice_id' => $invoice->id,
            'qty' => 1, 'price_per_item' => 100, 'vat_percent' => 24, 'product_descr' => 'Υπηρεσίες',
        ]);

        return $invoice->fresh('lines');
    }

    public function test_it_returns_valid_ubl_xml_with_the_el_vat_prefix(): void
    {
        $invoice = $this->invoice();

        $out = app(PeppolUblTool::class)->run($this->tenant, ['invoice' => 'ΤΠΥ1001']);

        $this->assertTrue($out['found']);
        $this->assertTrue($out['built']);
        $this->assertTrue($out['valid']);
        $this->assertSame('ΤΠΥ1001', $out['code']);
        $this->assertGreaterThan(0, $out['xml_bytes']);
        // The EL/GR distinction: EL-prefixed VAT, GR country code, never GR-prefixed VAT.
        $this->assertStringContainsString('EL800561849', $out['xml']);
        $this->assertStringNotContainsString('GR800561849', $out['xml']);
        $this->assertMatchesRegularExpression('/<cbc:IdentificationCode[^>]*>GR<\/cbc:IdentificationCode>/', $out['xml']);
    }

    public function test_it_reports_not_found_for_an_unknown_reference(): void
    {
        $out = app(PeppolUblTool::class)->run($this->tenant, ['invoice' => 'ΔΕΝ-ΥΠΑΡΧΕΙ']);

        $this->assertFalse($out['found']);
    }

    public function test_it_requires_an_invoice_reference(): void
    {
        $out = app(PeppolUblTool::class)->run($this->tenant, []);

        $this->assertArrayHasKey('error', $out);
    }
}
