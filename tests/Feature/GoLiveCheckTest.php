<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\InvoiceLine;
use App\Models\InvoiceType;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\VatCategory;
use App\Support\GoLive\GoLiveCheckReport;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * `ekdosi:go-live-check` — per-tenant cutover-readiness gates. FAIL = legal/
 * correctness blocker (exit 2); WARN = advisory (exit 0); SKIP = N/A to the
 * tenant's provider. Read-only — no AADE, no Firebird, no mutation.
 */
class GoLiveCheckTest extends TestCase
{
    use RefreshDatabase;

    /** A gr-mydata tenant ready to file: production creds + mode, valid type + default VAT. */
    private function readyTenant(): Company
    {
        $c = Company::create([
            'name' => 'GoLive OE', 'slug' => 'gl-'.uniqid(), 'country_code' => 'GR',
            'einvoice_provider' => 'gr-mydata', 'mydata_mode' => 'production', 'afm' => '800561849',
            'mydata_aade_id_production' => 'PRODUSER', 'mydata_subscription_key_production' => 'PRODKEY',
        ]);
        $this->grType($c);
        $this->vat($c, 24);

        return $c;
    }

    private function grType(Company $c, array $attrs = []): InvoiceType
    {
        return InvoiceType::create(array_merge([
            'company_id' => $c->id, 'code' => 'TPY', 'name' => 'Τιμολόγιο', 'invcount' => 1,
            'mydata_type' => '11.2', 'mydata_income_class' => 'E3_561_003', 'mydata_income_class_category' => 'category1_3',
        ], $attrs));
    }

    private function vat(Company $c, float $rate, bool $default = true): VatCategory
    {
        return VatCategory::create([
            'company_id' => $c->id, 'description' => $rate.'%', 'rate' => $rate, 'is_default' => $default,
        ]);
    }

    private function report(Company $c): array
    {
        return app(GoLiveCheckReport::class)->build($c);
    }

    private function gate(array $report, string $key): array
    {
        foreach ($report['gates'] as $g) {
            if ($g['key'] === $key) {
                return $g;
            }
        }
        $this->fail("gate '{$key}' not found");
    }

    public function test_ready_tenant_is_ready_and_exits_zero(): void
    {
        $c = $this->readyTenant();

        $report = $this->report($c);
        $this->assertSame('ready', $report['overall']);
        $this->assertSame('pass', $this->gate($report, 'mydata_prod_creds')['status']);
        $this->assertSame('pass', $this->gate($report, 'mydata_mode')['status']);
        $this->assertSame('pass', $this->gate($report, 'vat_default')['status']);

        $this->artisan('ekdosi:go-live-check', ['--tenant' => $c->slug])->assertExitCode(0);
    }

    public function test_missing_production_creds_fails(): void
    {
        $c = $this->readyTenant();
        $c->update(['mydata_aade_id_production' => null, 'mydata_subscription_key_production' => null]);

        $report = $this->report($c);
        $this->assertSame('fail', $this->gate($report, 'mydata_prod_creds')['status']);
        $this->assertSame('not_ready', $report['overall']);

        $this->artisan('ekdosi:go-live-check', ['--tenant' => $c->slug])->assertExitCode(2);
    }

    public function test_sandbox_mode_warns_but_stays_ready(): void
    {
        $c = $this->readyTenant();
        $c->update(['mydata_mode' => 'sandbox']); // production creds still present

        $report = $this->report($c);
        $this->assertSame('warn', $this->gate($report, 'mydata_mode')['status']);
        $this->assertSame('ready', $report['overall']); // warn doesn't block

        $this->artisan('ekdosi:go-live-check', ['--tenant' => $c->slug])->assertExitCode(0);
    }

    public function test_estonian_tenant_skips_mydata_gates(): void
    {
        $c = Company::create([
            'name' => 'Tallinn OU', 'slug' => 'ee-'.uniqid(), 'country_code' => 'EE',
            'einvoice_provider' => 'ee-peppol', 'mydata_mode' => 'off',
        ]);
        // Needs a numbering-capable type + a default VAT (those gates aren't myDATA).
        InvoiceType::create(['company_id' => $c->id, 'code' => 'ARVE', 'name' => 'Invoice', 'invcount' => 1, 'mydata_type' => null]);
        $this->vat($c, 22);

        $report = $this->report($c);

        foreach (['invoice_types', 'vat_rates', 'mydata_prod_creds', 'mydata_mode'] as $k) {
            $this->assertSame('skip', $this->gate($report, $k)['status'], "{$k} must SKIP for ee-peppol");
        }
        $this->assertSame('pass', $this->gate($report, 'vat_default')['status']);
        $this->assertSame('pass', $this->gate($report, 'numbering')['status']);
        $this->assertSame('ready', $report['overall']);

        $this->artisan('ekdosi:go-live-check', ['--tenant' => $c->slug])->assertExitCode(0);
    }

    public function test_gr_provider_without_provider_config_is_not_falsely_ready(): void
    {
        // A ΥΠΑΗΕΣ-provider tenant files electronically via a provider; the myDATA
        // transport gates SKIP, but provider_live must FAIL when the provider
        // isn't live — else the gate would falsely report READY.
        $c = Company::create([
            'name' => 'Provider OE', 'slug' => 'pv-'.uniqid(), 'country_code' => 'GR',
            'einvoice_provider' => 'gr-provider', 'einvoice_provider_mode' => 'off', 'mydata_mode' => 'off',
        ]);
        $this->grType($c);
        $this->vat($c, 24);

        $report = $this->report($c);

        $this->assertSame('skip', $this->gate($report, 'mydata_prod_creds')['status'], 'direct-myDATA creds skip for a provider tenant');
        $this->assertSame('skip', $this->gate($report, 'mydata_mode')['status']);
        $this->assertSame('fail', $this->gate($report, 'provider_live')['status'], 'provider not live → FAIL, not a silent skip');
        // Document-structure gates still apply (the provider files to AADE too).
        $this->assertSame('pass', $this->gate($report, 'invoice_types')['status']);
        $this->assertSame('not_ready', $report['overall']);

        $this->artisan('ekdosi:go-live-check', ['--tenant' => $c->slug])->assertExitCode(2);
    }

    public function test_gr_provider_live_and_configured_is_ready(): void
    {
        $c = Company::create([
            'name' => 'Provider Live OE', 'slug' => 'pvl-'.uniqid(), 'country_code' => 'GR',
            'einvoice_provider' => 'gr-provider', 'einvoice_provider_mode' => 'production',
            'einvoice_provider_key' => 'invosign', 'mydata_mode' => 'off',
        ]);
        $this->grType($c);
        $this->vat($c, 24);

        $report = $this->report($c);
        $this->assertSame('pass', $this->gate($report, 'provider_live')['status']);
        $this->assertSame('ready', $report['overall']);
    }

    public function test_no_default_vat_fails(): void
    {
        $c = $this->readyTenant();
        VatCategory::where('company_id', $c->id)->update(['is_default' => false]);

        $report = $this->report($c);
        $this->assertSame('fail', $this->gate($report, 'vat_default')['status']);
        $this->assertSame('not_ready', $report['overall']);
    }

    public function test_totals_drift_beyond_tolerance_fails(): void
    {
        $c = $this->readyTenant();
        $cat = ProductCategory::create(['company_id' => $c->id, 'description_short' => 'HW', 'markup' => 0]);
        $vat = VatCategory::where('company_id', $c->id)->first();
        $product = Product::create([
            'company_id' => $c->id, 'description_short' => 'SSD',
            'product_category_id' => $cat->id, 'vat_category_id' => $vat->id, 'track_stock' => false,
        ]);
        $customer = Customer::create(['company_id' => $c->id, 'name' => 'Π', 'afm' => '123456789']);
        $type = InvoiceType::where('company_id', $c->id)->first();

        $inv = Invoice::create([
            'company_id' => $c->id, 'invcode' => 'TPY'.uniqid(), 'code' => random_int(1, 99999),
            'invoice_type_id' => $type->id, 'customer_id' => $customer->id,
            'issued_at' => now(), 'local_status' => 'active', 'header_discount_percent' => 0,
        ]);
        InvoiceLine::create([
            'company_id' => $c->id, 'invoice_id' => $inv->id, 'product_id' => $product->id,
            'qty' => 1, 'price_per_item' => 100, 'vat_percent' => 24, // → net_price 100, gross 124
        ]);
        // Plant a stored total that drifts well beyond the ~1-cent tolerance,
        // bypassing model events so it stays wrong.
        Invoice::where('id', $inv->id)->update(['net_total' => 105, 'gross_total' => 124]);

        $report = $this->report($c);
        $this->assertSame('fail', $this->gate($report, 'totals_drift')['status']);
        $this->assertSame('not_ready', $report['overall']);
    }

    public function test_unknown_tenant_exits_one(): void
    {
        $this->artisan('ekdosi:go-live-check', ['--tenant' => 'nope-nope'])->assertExitCode(1);
    }

    public function test_missing_tenant_option_exits_one(): void
    {
        $this->artisan('ekdosi:go-live-check')->assertExitCode(1);
    }

    public function test_json_output_carries_overall_and_gates(): void
    {
        $c = $this->readyTenant();

        $report = $this->report($c);
        $this->assertArrayHasKey('overall', $report);
        $this->assertArrayHasKey('gates', $report);
        $this->assertSame('ready', $report['overall']);
        // JSON exit code mirrors the table path.
        $this->artisan('ekdosi:go-live-check', ['--tenant' => $c->slug, '--json' => true])->assertExitCode(0);
    }
}
