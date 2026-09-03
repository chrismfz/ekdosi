<?php

namespace Tests\Feature\Preflight;

use App\Models\Company;
use App\Models\ProductCategory;
use App\Models\VatCategory;
use App\Support\Preflight\ReadinessReport;
use App\Support\Tenancy\CompanyContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ReadinessReportTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        // These tests pin the ambient tenant to simulate the panel; don't leak it.
        app(CompanyContext::class)->clear();
        parent::tearDown();
    }

    private function tenant(string $provider = 'gr-mydata'): Company
    {
        return Company::create([
            'name' => 'T '.uniqid(), 'slug' => 't-'.uniqid(),
            'country_code' => 'GR', 'einvoice_provider' => $provider, 'mydata_mode' => 'off',
        ]);
    }

    private function section(array $report, string $key): ?array
    {
        foreach ($report['sections'] as $s) {
            if ($s['key'] === $key) {
                return $s;
            }
        }

        return null;
    }

    public function test_bare_tenant_warns_on_missing_lookups(): void
    {
        $report = app(ReadinessReport::class)->forCompany($this->tenant());

        $lookups = $this->section($report, 'lookups');
        $this->assertSame('warn', $lookups['status']);
        $this->assertCount(7, $lookups['items']); // ΦΠΑ, τύποι, πληρωμές, μονάδες, αποστολή, διακίνηση, κατηγορίες
        $this->assertSame('warn', $lookups['items'][0]['status']);
    }

    public function test_zero_rate_vat_without_exemption_is_a_mydata_fail(): void
    {
        $tenant = $this->tenant();
        VatCategory::create(['company_id' => $tenant->id, 'description' => '0%', 'rate' => 0]); // no §8.3 → [217]

        $report = app(ReadinessReport::class)->forCompany($tenant);

        $this->assertSame('fail', $this->section($report, 'mydata')['status']);
        $this->assertSame('fail', $report['status']); // company status = worst section
    }

    public function test_non_aade_tenant_mydata_section_is_ok(): void
    {
        $report = app(ReadinessReport::class)->forCompany($this->tenant('none'));

        $this->assertSame('ok', $this->section($report, 'mydata')['status']);
    }

    public function test_product_category_without_income_class_warns(): void
    {
        $tenant = $this->tenant();
        ProductCategory::create(['company_id' => $tenant->id, 'description_short' => 'HW', 'markup' => 0]); // no §8.6

        $products = $this->section(app(ReadinessReport::class)->forCompany($tenant), 'products');
        $this->assertSame('warn', $products['status']);
    }

    public function test_product_category_with_income_class_is_ok(): void
    {
        $tenant = $this->tenant();
        ProductCategory::create([
            'company_id' => $tenant->id, 'description_short' => 'HW', 'markup' => 0,
            'mydata_income_class_category' => 'category1_3',
        ]);

        $products = $this->section(app(ReadinessReport::class)->forCompany($tenant), 'products');
        $this->assertSame('ok', $products['status']);
    }

    public function test_whmcs_section_only_appears_with_an_integration(): void
    {
        $plain = app(ReadinessReport::class)->forCompany($this->tenant());
        $this->assertNull($this->section($plain, 'whmcs'));

        $withWhmcs = $this->tenant();
        $withWhmcs->forceFill([
            'whmcs_api_url' => 'https://whmcs.example/includes/api.php',
            'whmcs_api_identifier' => 'id', 'whmcs_api_secret' => 'secret',
        ])->save();

        $report = app(ReadinessReport::class)->forCompany($withWhmcs->fresh());
        $whmcs = $this->section($report, 'whmcs');
        $this->assertNotNull($whmcs);
        $this->assertSame('ok', $whmcs['status']); // maps optional → ok even with none
    }

    public function test_other_tenant_is_not_masked_by_the_ambient_pinned_tenant(): void
    {
        // The panel pins CompanyContext to the SELECTED tenant (TenantSet). Building
        // ANOTHER company must re-pin to it — otherwise its scoped queries AND-combine
        // with the ambient company_id and silently return an empty (false-warn) tenant.
        $selected = $this->tenant();
        $other = $this->tenant();
        VatCategory::create(['company_id' => $other->id, 'description' => 'ΦΠΑ 24', 'rate' => 24]);

        app(CompanyContext::class)->set($selected); // simulate the pinned panel tenant

        $lookups = $this->section(app(ReadinessReport::class)->forCompany($other), 'lookups');
        // items[0] = ΦΠΑ; it must see $other's own category despite the ambient $selected.
        $this->assertSame('ok', $lookups['items'][0]['status']);
        $this->assertStringContainsString(': 1', $lookups['items'][0]['message']);
    }

    public function test_build_lists_every_company_ordered_by_name(): void
    {
        Company::create(['name' => 'Beta', 'slug' => 'b-'.uniqid(), 'country_code' => 'GR', 'einvoice_provider' => 'none', 'mydata_mode' => 'off']);
        Company::create(['name' => 'Alpha', 'slug' => 'a-'.uniqid(), 'country_code' => 'GR', 'einvoice_provider' => 'none', 'mydata_mode' => 'off']);

        $report = app(ReadinessReport::class)->build();

        $this->assertGreaterThanOrEqual(2, count($report));
        $names = array_column($report, 'name');
        $this->assertSame($names, array_values(collect($names)->sort()->all())); // sorted
    }

    public function test_build_is_not_masked_by_a_pinned_ambient_tenant(): void
    {
        // The real page path: mount()→build() while TenantSet has pinned ONE tenant.
        // Every OTHER company in the list must still see its own scoped rows.
        $pinned = $this->tenant('none');
        $sibling = $this->tenant('none');
        VatCategory::create(['company_id' => $sibling->id, 'description' => 'ΦΠΑ 24', 'rate' => 24]);

        app(CompanyContext::class)->set($pinned); // simulate the selected panel tenant

        $report = app(ReadinessReport::class)->build();
        $siblingReport = collect($report)->firstWhere('company_id', $sibling->id);
        $this->assertNotNull($siblingReport);
        $lookups = $this->section($siblingReport, 'lookups');
        $this->assertSame('ok', $lookups['items'][0]['status']); // ΦΠΑ item sees the sibling's own row
        $this->assertStringContainsString(': 1', $lookups['items'][0]['message']);
    }
}
