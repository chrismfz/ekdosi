<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\CompanyBackupRun;
use App\Models\CompanyBackupSetting;
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
            // MYD-006: a chosen classification policy is required for a ready tenant.
            'business_activity_type' => 'services',
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

    public function test_backup_gate_warns_when_enabled_but_never_ran(): void
    {
        // OPS-15: «enabled» alone is not DR readiness — a toggle that has never
        // produced a backup must NOT pass the cutover gate.
        $c = $this->readyTenant();
        CompanyBackupSetting::create([
            'company_id' => $c->id, 'enabled' => true, 'frequency' => 'daily', 'bucket' => 'full',
        ]);

        $gate = $this->gate($this->report($c), 'backup');
        $this->assertSame('warn', $gate['status']);
        $this->assertStringContainsString('ΚΑΜΙΑ επιτυχημένη', $gate['detail']);
    }

    public function test_backup_gate_passes_with_a_recent_successful_run(): void
    {
        $c = $this->readyTenant();
        CompanyBackupSetting::create([
            'company_id' => $c->id, 'enabled' => true, 'frequency' => 'daily', 'bucket' => 'full',
        ]);
        CompanyBackupRun::create([
            'company_id' => $c->id, 'status' => 'ok',
            'bucket' => 'full',
            'secrets_mode' => 'raw',
            'started_at' => now()->subHours(2), 'finished_at' => now()->subHours(2),
        ]);

        $this->assertSame('pass', $this->gate($this->report($c), 'backup')['status']);
    }

    public function test_backup_gate_warns_when_last_successful_run_is_stale(): void
    {
        $c = $this->readyTenant();
        CompanyBackupSetting::create([
            'company_id' => $c->id, 'enabled' => true, 'frequency' => 'daily', 'bucket' => 'full',
        ]);
        // Only run is 10 days old (> BACKUP_STALE_DAYS); a failed recent one
        // must NOT count as evidence.
        CompanyBackupRun::create([
            'company_id' => $c->id, 'status' => 'ok',
            'bucket' => 'full',
            'secrets_mode' => 'raw',
            'started_at' => now()->subDays(10), 'finished_at' => now()->subDays(10),
        ]);
        CompanyBackupRun::create([
            'company_id' => $c->id, 'status' => 'failed',
            'bucket' => 'full',
            'secrets_mode' => 'raw',
            'started_at' => now()->subHour(), 'finished_at' => now()->subHour(),
        ]);

        $gate = $this->gate($this->report($c), 'backup');
        $this->assertSame('warn', $gate['status']);
        $this->assertStringContainsString('ημ.', $gate['detail']);
    }

    public function test_secrets_at_rest_gate_reflects_the_explicit_decision(): void
    {
        // SEC-1: plaintext without acknowledgement WARNS; acknowledging it (or
        // encrypting) PASSES. Never a FAIL — plaintext is an accepted trade-off.
        $c = $this->readyTenant();

        config(['ekdosi.secrets.encrypt_at_rest' => false, 'ekdosi.secrets.plaintext_acknowledged' => false]);
        $this->assertSame('warn', $this->gate($this->report($c), 'secrets_at_rest')['status']);

        config(['ekdosi.secrets.plaintext_acknowledged' => true]);
        $this->assertSame('pass', $this->gate($this->report($c), 'secrets_at_rest')['status']);

        config(['ekdosi.secrets.encrypt_at_rest' => true, 'ekdosi.secrets.plaintext_acknowledged' => false]);
        $this->assertSame('pass', $this->gate($this->report($c), 'secrets_at_rest')['status']);
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

        foreach (['invoice_types', 'vat_rates', 'mydata_prod_creds', 'mydata_mode', 'classification_policy'] as $k) {
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
            'business_activity_type' => 'services', 'afm' => '800561849',
            // PROV-005: the full issuer identity InvoSign's extension requires.
            'kad_primary' => '6201', 'tax_office' => 'Α΄ ΑΘΗΝΩΝ',
            'address' => 'Οδός 1', 'postcode' => '11111', 'city' => 'Αθήνα',
            'phone' => '2100000000', 'email' => 'billing@pvl.gr',
        ]);
        $this->grType($c);
        $this->vat($c, 24);

        $report = $this->report($c);
        $this->assertSame('pass', $this->gate($report, 'provider_live')['status']);
        $this->assertSame('pass', $this->gate($report, 'provider_issuer')['status']);
        $this->assertSame('ready', $report['overall']);
    }

    public function test_blank_issuer_afm_fails_cutover(): void
    {
        // PROV-005: the issuer ΑΦΜ is the AADE-core identity — a blank one is a wire
        // rejection on the first filing, for a direct-myDATA tenant too (not only
        // providers), and no other gate validated it before.
        $c = $this->readyTenant();          // gr-mydata, otherwise ready
        $c->update(['afm' => '']);

        $report = $this->report($c);
        $this->assertSame('fail', $this->gate($report, 'issuer_afm')['status']);
        $this->assertSame('not_ready', $report['overall']);
    }

    public function test_gr_provider_missing_issuer_fields_fails_cutover(): void
    {
        // PROV-005: provider live + configured, but the issuer identity InvoSign's
        // extension requires is incomplete (no ΔΟΥ/ΚΑΔ/address) → wire-level
        // rejection on the first real document → cutover FAIL.
        $c = Company::create([
            'name' => 'Provider Half OE', 'slug' => 'pvh-'.uniqid(), 'country_code' => 'GR',
            'einvoice_provider' => 'gr-provider', 'einvoice_provider_mode' => 'production',
            'einvoice_provider_key' => 'invosign', 'mydata_mode' => 'off',
            'business_activity_type' => 'services', 'afm' => '800561849',
        ]);
        $this->grType($c);
        $this->vat($c, 24);

        $report = $this->report($c);
        $this->assertSame('fail', $this->gate($report, 'provider_issuer')['status']);
        $this->assertSame('not_ready', $report['overall']);
        $this->artisan('ekdosi:go-live-check', ['--tenant' => $c->slug])->assertExitCode(2);
    }

    public function test_classification_policy_unset_fails_the_cutover(): void
    {
        // MYD-006: an AADE-filing tenant that has not chosen its business-activity
        // type cannot go live — the goods income bucket would be a silent guess.
        $c = $this->readyTenant();
        $c->update(['business_activity_type' => null]);

        $report = $this->report($c);
        $gate = $this->gate($report, 'classification_policy');
        $this->assertSame('fail', $gate['status']);
        $this->assertStringContainsString('είδος δραστηριότητας', $gate['detail']);
        $this->assertSame('not_ready', $report['overall']);

        $this->artisan('ekdosi:go-live-check', ['--tenant' => $c->slug])->assertExitCode(2);
    }

    public function test_classification_policy_selected_passes(): void
    {
        $c = $this->readyTenant(); // business_activity_type = 'services'

        $gate = $this->gate($this->report($c), 'classification_policy');
        $this->assertSame('pass', $gate['status']);
    }

    public function test_mixed_policy_passes_with_a_reminder_and_never_blocks(): void
    {
        // A mixed tenant PASSES the selection gate (the required act is done) and is
        // reminded to classify its GOODS categories — but we never WARN on a count of
        // null-override categories (a services category is legitimately null), so the
        // reminder is noise-free and the tenant reads ready either way.
        $c = $this->readyTenant();
        $c->update(['business_activity_type' => 'mixed']);
        // Only a services category, legitimately unclassified → still a clean PASS.
        ProductCategory::create(['company_id' => $c->id, 'description_short' => 'Υπηρεσίες', 'markup' => 0]);

        $report = $this->report($c);
        $gate = $this->gate($report, 'classification_policy');
        $this->assertSame('pass', $gate['status']);
        $this->assertStringContainsString('μικτή', $gate['detail']);
        $this->assertStringContainsString('θύμισε', $gate['detail']); // reminder, no goods category yet
        $this->assertSame('ready', $report['overall']);

        // Once a GOODS category is classified, the reminder drops.
        ProductCategory::create([
            'company_id' => $c->id, 'description_short' => 'Προϊόντα', 'markup' => 0,
            'mydata_income_class_category' => 'category1_2',
        ]);
        $this->assertStringNotContainsString('θύμισε', $this->gate($this->report($c), 'classification_policy')['detail']);
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
