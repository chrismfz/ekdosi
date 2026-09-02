<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Customer;
use App\Models\VatCategory;
use App\Support\MyData\Codes;
use App\Support\MyData\ReverseCharge;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ReverseChargeHelperTest extends TestCase
{
    use RefreshDatabase;

    public function test_eu_non_greek_detection(): void
    {
        $this->assertTrue(ReverseCharge::isEuNonGreek('AT'));
        $this->assertTrue(ReverseCharge::isEuNonGreek('de'));   // case-insensitive
        $this->assertFalse(ReverseCharge::isEuNonGreek('GR'));
        $this->assertFalse(ReverseCharge::isEuNonGreek('EL'));   // VIES code for Greece
        $this->assertFalse(ReverseCharge::isEuNonGreek('US'));
        $this->assertFalse(ReverseCharge::isEuNonGreek(null));
    }

    public function test_applies_to_eu_customer_with_vat(): void
    {
        $tenant = Company::factory()->create();

        $eu = Customer::create(['company_id' => $tenant->id, 'name' => 'AT Co', 'country' => 'AT', 'vat_vies' => 'ATU18522105']);
        $this->assertTrue(ReverseCharge::appliesTo($eu));

        $euAfm = Customer::create(['company_id' => $tenant->id, 'name' => 'IT Co', 'country' => 'IT', 'afm' => '12345678901']);
        $this->assertTrue(ReverseCharge::appliesTo($euAfm));
    }

    public function test_does_not_apply_to_greek_or_vatless_or_non_eu(): void
    {
        $tenant = Company::factory()->create();

        $gr = Customer::create(['company_id' => $tenant->id, 'name' => 'GR Co', 'country' => 'GR', 'afm' => '123456789']);
        $this->assertFalse(ReverseCharge::appliesTo($gr));

        $euNoVat = Customer::create(['company_id' => $tenant->id, 'name' => 'DE no VAT', 'country' => 'DE']);
        $this->assertFalse(ReverseCharge::appliesTo($euNoVat));

        $nonEu = Customer::create(['company_id' => $tenant->id, 'name' => 'US Co', 'country' => 'US', 'vat_vies' => 'US123']);
        $this->assertFalse(ReverseCharge::appliesTo($nonEu));
    }

    public function test_should_default_zero_vat_with_at_least_one_exempt_category(): void
    {
        $tenant = Company::factory()->create();
        $eu = Customer::create(['company_id' => $tenant->id, 'name' => 'AT', 'country' => 'AT', 'vat_vies' => 'ATU18522105']);

        // No 0% category configured → no auto-default (0% isn't a fileable rate).
        $this->assertFalse(ReverseCharge::shouldDefaultZeroVat($tenant, $eu));

        // One 0% category WITH an exemption reason → auto-default on.
        VatCategory::create([
            'company_id' => $tenant->id, 'description' => '0% ενδοκοινοτική υπηρεσία',
            'rate' => 0, 'vat_exemption_category' => 4,
        ]);
        $this->assertTrue(ReverseCharge::shouldDefaultZeroVat($tenant, $eu));

        // MYD-007: a SECOND 0% category no longer disables the default — the per-line
        // reason (set from the invoice type) disambiguates, so 0% still defaults.
        VatCategory::create([
            'company_id' => $tenant->id, 'description' => '0% ενδοκοινοτικά αγαθά',
            'rate' => 0, 'vat_exemption_category' => 14,
        ]);
        $this->assertTrue(ReverseCharge::shouldDefaultZeroVat($tenant, $eu));
    }

    public function test_should_not_default_for_greek_customer_even_with_category(): void
    {
        $tenant = Company::factory()->create();
        VatCategory::create([
            'company_id' => $tenant->id, 'description' => '0%',
            'rate' => 0, 'vat_exemption_category' => 16,
        ]);
        $gr = Customer::create(['company_id' => $tenant->id, 'name' => 'GR', 'country' => 'GR', 'afm' => '123456789']);

        $this->assertFalse(ReverseCharge::shouldDefaultZeroVat($tenant, $gr));
    }

    public function test_exemption_options_are_human_readable_and_code_16_is_article_45(): void
    {
        $opts = Codes::vatExemptionOptions();

        // 31 reasons, keyed by code, labelled with the legal citation.
        $this->assertCount(31, $opts);
        $this->assertArrayHasKey(16, $opts);
        $this->assertStringContainsString('άρθρο 45', $opts[16]);
        $this->assertSame(16, Codes::VAT_EXEMPTION_INTRACOMMUNITY);
        // Every advertised category has a label (no bare "Κατηγορία N").
        foreach (Codes::VAT_EXEMPTION_CATEGORIES as $code) {
            $this->assertArrayHasKey($code, Codes::VAT_EXEMPTION_LABELS, "missing label for §8.3 code {$code}");
        }
    }
}
