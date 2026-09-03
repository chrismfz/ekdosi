<?php

namespace Tests\Feature\EInvoice;

use App\Models\Company;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\InvoiceType;
use App\Models\VatCategory;
use App\Services\EInvoice\ProviderPreflight;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * P4: the read-only provider readiness audit + the einvoice:preflight /
 * einvoice:provider-test-submit commands. No network.
 */
class ProviderPreflightTest extends TestCase
{
    use RefreshDatabase;

    private function readyTenant(array $overrides = []): Company
    {
        $c = Company::create(array_merge([
            'name' => 'Ready ΑΕ', 'slug' => 'ready-'.uniqid(), 'country_code' => 'GR',
            'einvoice_provider' => 'gr-provider', 'einvoice_provider_key' => 'invosign',
            'einvoice_provider_mode' => 'sandbox', 'afm' => '800561849',
            // Full issuer identity InvoSign's <API_Issuer> requires (PROV-005).
            'kad_primary' => '6201', 'tax_office' => 'Α΄ ΑΘΗΝΩΝ',
            'address' => 'Οδός 1', 'postcode' => '11111', 'city' => 'Αθήνα',
            'phone' => '2100000000', 'email' => 'billing@ready.gr',
            'einvoice_provider_config' => ['demo_base_url' => 'https://demo', 'demo_token' => 'T'],
            'mydata_aade_id_sandbox' => 'U', 'mydata_subscription_key_sandbox' => 'K',
        ], $overrides));
        InvoiceType::create(['company_id' => $c->id, 'code' => 'TPY', 'name' => 'ΤΠΥ', 'invcount' => 1, 'mydata_type' => '2.1']);

        return $c;
    }

    private function statuses(Company $c): array
    {
        return array_map(fn ($x) => $x['status'], app(ProviderPreflight::class)->audit($c));
    }

    public function test_fully_configured_tenant_is_ready(): void
    {
        $c = $this->readyTenant();
        $this->assertTrue(app(ProviderPreflight::class)->isReady($c));
        $this->assertNotContains('fail', $this->statuses($c));
    }

    public function test_missing_afm_and_invoice_types_fail(): void
    {
        $c = Company::create([
            'name' => 'Bare', 'slug' => 'bare-'.uniqid(), 'country_code' => 'GR',
            'einvoice_provider' => 'gr-provider', 'einvoice_provider_key' => 'invosign',
            'einvoice_provider_mode' => 'sandbox',
        ]);
        $this->assertFalse(app(ProviderPreflight::class)->isReady($c));
        $this->assertContains('fail', $this->statuses($c));
    }

    public function test_unregistered_provider_transport_fails(): void
    {
        // 'sbz' is labelled but has NO transport class registered → Null → fail.
        $c = $this->readyTenant(['einvoice_provider_key' => 'sbz', 'einvoice_provider_config' => ['base_url' => 'x', 'api_key' => 'y']]);
        $this->assertFalse(app(ProviderPreflight::class)->isReady($c));
    }

    public function test_credential_check_is_mode_aware(): void
    {
        // Production mode but only the SANDBOX (demo_*) creds are filled → fail.
        $c = $this->readyTenant([
            'einvoice_provider_mode' => 'production',
            'einvoice_provider_config' => ['demo_base_url' => 'https://demo', 'demo_token' => 'T'],
        ]);
        $this->assertFalse(app(ProviderPreflight::class)->isReady($c));

        // Fill the production pair too → ready.
        $c->update(['einvoice_provider_config' => [
            'demo_base_url' => 'https://demo', 'demo_token' => 'T',
            'base_url' => 'https://live', 'token' => 'L',
        ]]);
        $this->assertTrue(app(ProviderPreflight::class)->isReady($c->fresh()));
    }

    public function test_missing_mandatory_issuer_field_fails(): void
    {
        // PROV-005: a tenant otherwise ready but missing a mandatory issuer field
        // (here ΔΟΥ) must FAIL — InvoSign rejects it at the wire otherwise.
        $c = $this->readyTenant(['tax_office' => '']);
        $this->assertFalse(app(ProviderPreflight::class)->isReady($c));

        $issuer = collect(app(ProviderPreflight::class)->audit($c))
            ->firstWhere('label', 'Στοιχεία εκδότη (πάροχος)');
        $this->assertSame('fail', $issuer['status']);
        $this->assertStringContainsString('ΔΟΥ', $issuer['detail']);
    }

    public function test_missing_contact_fields_only_warn_not_fail(): void
    {
        // email/phone are advisory: missing → WARN, never a filing blocker.
        $c = $this->readyTenant(['phone' => '', 'email' => '']);
        $this->assertTrue(app(ProviderPreflight::class)->isReady($c), 'contact gaps do not block');

        $contact = collect(app(ProviderPreflight::class)->audit($c))
            ->firstWhere('label', 'Επικοινωνία εκδότη');
        $this->assertSame('warn', $contact['status']);
        $this->assertStringContainsString('Email', $contact['detail']);
        $this->assertStringContainsString('Τηλέφωνο', $contact['detail']);
    }

    public function test_mismatched_mydata_read_pair_warns_not_fail(): void
    {
        // sandbox aade-id present but its subscription-key missing (a mash-up that
        // read green before) → WARN naming the missing half, isReady still true.
        $c = $this->readyTenant(['mydata_subscription_key_sandbox' => '']);
        $this->assertTrue(app(ProviderPreflight::class)->isReady($c));

        $reco = collect(app(ProviderPreflight::class)->audit($c))
            ->firstWhere('label', 'myDATA (έλεγχος/συμφωνία)');
        $this->assertSame('warn', $reco['status']);
        $this->assertStringContainsString('subscription-key', $reco['detail']);
    }

    public function test_non_provider_tenant_returns_single_warn(): void
    {
        $c = Company::create(['name' => 'md', 'slug' => 'md-'.uniqid(), 'country_code' => 'GR', 'einvoice_provider' => 'gr-mydata']);
        $audit = app(ProviderPreflight::class)->audit($c);
        $this->assertCount(1, $audit);
        $this->assertSame('warn', $audit[0]['status']);
    }

    public function test_preflight_command_exit_codes(): void
    {
        $ready = $this->readyTenant();
        $this->artisan('einvoice:preflight', ['--tenant' => $ready->slug])->assertExitCode(0);

        $broken = Company::create([
            'name' => 'Broken', 'slug' => 'broken-'.uniqid(), 'country_code' => 'GR',
            'einvoice_provider' => 'gr-provider', 'einvoice_provider_key' => 'invosign', 'einvoice_provider_mode' => 'sandbox',
        ]);
        $this->artisan('einvoice:preflight', ['--tenant' => $broken->slug])->assertExitCode(2);
    }

    public function test_test_submit_dry_run_prints_payload_without_network(): void
    {
        $c = $this->readyTenant(['einvoice_provider_config' => ['demo_base_url' => 'https://demo', 'demo_token' => 'SENTINEL-TOKEN']]);
        $customer = Customer::create(['company_id' => $c->id, 'name' => 'Π', 'afm' => '997073525']);
        VatCategory::create(['company_id' => $c->id, 'description' => '24%', 'rate' => 24, 'is_default' => true]);
        $type = InvoiceType::where('company_id', $c->id)->first();
        $invoice = Invoice::create([
            'company_id' => $c->id, 'invcode' => 'TPY1', 'code' => 1, 'invoice_type_id' => $type->id,
            'customer_id' => $customer->id, 'issued_at' => now(), 'company_name' => 'Π', 'vat_no' => '997073525',
        ]);
        $invoice->lines()->create(['company_id' => $c->id, 'product_descr' => 'Υ', 'qty' => 1, 'price_per_item' => 50, 'vat_percent' => 24]);

        $this->artisan('einvoice:provider-test-submit', ['invoice' => $invoice->id])
            ->expectsOutputToContain('API_InvoiceDetails')
            ->doesntExpectOutputToContain('SENTINEL-TOKEN') // the token must NEVER be in the payload
            ->assertExitCode(0);
    }
}
