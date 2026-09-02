<?php

namespace Tests\Unit\MyData;

use App\Support\MyData\Codes;
use App\Support\MyData\VatExemptionGuidance;
use PHPUnit\Framework\TestCase;

/**
 * MYD-007: lock the §8.3 exemption mapping — the exact bug was a generic «16 for
 * everything intracommunity». These pin the corrected mapping and the
 * refuse-rather-than-guess boundary, and sanity-check the codes against the real
 * spec table (Codes::VAT_EXEMPTION_LABELS) so a typo can't slip through.
 */
class VatExemptionGuidanceTest extends TestCase
{
    public function test_intra_eu_service_is_code_4_not_16_or_14(): void
    {
        $this->assertSame(4, VatExemptionGuidance::recommendForType('2.2'));
        $this->assertSame(4, VatExemptionGuidance::SCENARIOS['intra_eu_service']['exemption_code']);
        // The historic defect: NOT 16 (domestic reverse charge), NOT 14 (goods).
        $this->assertNotSame(16, VatExemptionGuidance::recommendForType('2.2'));
        $this->assertNotSame(14, VatExemptionGuidance::recommendForType('2.2'));
    }

    public function test_intra_eu_goods_is_code_14(): void
    {
        $this->assertSame(14, VatExemptionGuidance::recommendForType('1.2'));
    }

    public function test_export_goods_is_code_8(): void
    {
        $this->assertSame(8, VatExemptionGuidance::recommendForType('1.3'));
    }

    public function test_ambiguous_types_refuse_to_guess(): void
    {
        // Domestic (1.1/2.1) and third-country services (2.3) have case-specific
        // reasons — the helper returns null so the operator decides.
        $this->assertNull(VatExemptionGuidance::recommendForType('1.1'));
        $this->assertNull(VatExemptionGuidance::recommendForType('2.1'));
        $this->assertNull(VatExemptionGuidance::recommendForType('2.3'));
        $this->assertNull(VatExemptionGuidance::recommendForType('11.1'));
        $this->assertNull(VatExemptionGuidance::recommendForType(null));
        $this->assertNull(VatExemptionGuidance::recommendForType(''));
    }

    public function test_every_scenario_code_is_a_valid_spec_reason(): void
    {
        foreach (VatExemptionGuidance::SCENARIOS as $key => $scenario) {
            $code = $scenario['exemption_code'];
            $this->assertContains(
                $code,
                Codes::VAT_EXEMPTION_CATEGORIES,
                "Scenario {$key} uses §8.3 code {$code} which is not a valid exemption category."
            );
            $this->assertArrayHasKey(
                $code,
                Codes::VAT_EXEMPTION_LABELS,
                "Scenario {$key} code {$code} has no spec label."
            );
        }
    }

    public function test_scenario_options_cover_the_scenarios(): void
    {
        $options = VatExemptionGuidance::scenarioOptions();
        $this->assertArrayHasKey('intra_eu_service', $options);
        $this->assertSame(
            array_keys(VatExemptionGuidance::SCENARIOS),
            array_keys($options)
        );
    }

    public function test_label_for_code_matches_the_spec_table(): void
    {
        $this->assertSame(Codes::VAT_EXEMPTION_LABELS[4], VatExemptionGuidance::labelForCode(4));
    }
}
