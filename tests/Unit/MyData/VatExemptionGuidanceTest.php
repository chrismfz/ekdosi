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

    public function test_domestic_reverse_charge_on_a_foreign_counterpart_type_is_blocked(): void
    {
        foreach (['1.2', '2.2', '1.3', '2.3'] as $type) {
            $this->assertSame(VatExemptionGuidance::CONFLICT_BLOCK, VatExemptionGuidance::typeConflict($type, 16)['level'] ?? null, "16 on {$type}");
        }
        // …and is exactly right on a domestic one.
        $this->assertNull(VatExemptionGuidance::typeConflict('1.1', 16));
        $this->assertNull(VatExemptionGuidance::typeConflict('2.1', 16));
    }

    public function test_intra_eu_goods_reason_outside_intra_eu_is_blocked(): void
    {
        foreach (['1.1', '2.1', '1.3', '2.3'] as $type) {
            $this->assertSame(VatExemptionGuidance::CONFLICT_BLOCK, VatExemptionGuidance::typeConflict($type, 14)['level'] ?? null, "14 on {$type}");
        }
        $this->assertNull(VatExemptionGuidance::typeConflict('1.2', 14));
        // A services 2.2 may still carry a goods line (mixed invoice) → warn only.
        $this->assertSame(VatExemptionGuidance::CONFLICT_WARN, VatExemptionGuidance::typeConflict('2.2', 14)['level']);
        // An EU buyer's goods exported outside the EU → 8 on a 1.2 is possible → warn only.
        $this->assertSame(VatExemptionGuidance::CONFLICT_WARN, VatExemptionGuidance::typeConflict('1.2', 8)['level']);
    }

    public function test_the_recommended_reason_for_each_type_is_never_a_conflict(): void
    {
        foreach (['2.2', '1.2', '1.3'] as $type) {
            $this->assertNull(VatExemptionGuidance::typeConflict($type, VatExemptionGuidance::recommendForType($type)), $type);
        }
        // Art. 18 on a third-country service is the normal case.
        $this->assertNull(VatExemptionGuidance::typeConflict('2.3', 4));
    }

    public function test_place_of_supply_on_a_domestic_type_is_only_a_warning(): void
    {
        $conflict = VatExemptionGuidance::typeConflict('1.1', 4);

        $this->assertSame(VatExemptionGuidance::CONFLICT_WARN, $conflict['level']);
        $this->assertStringContainsString('Αιτία 4 σε τύπο 1.1', $conflict['message']);
    }

    public function test_the_message_names_the_right_reason_when_the_type_has_one(): void
    {
        $this->assertStringContainsString('η αιτία είναι 4 ('.Codes::VAT_EXEMPTION_LABELS[4].')', VatExemptionGuidance::typeConflict('2.2', 16)['message']);
        // 2.3 has no single right reason → no «η αιτία είναι» suggestion.
        $this->assertStringNotContainsString('η αιτία είναι', VatExemptionGuidance::typeConflict('2.3', 16)['message']);
        // A warning never tells the operator to change a reason that may be right
        // (a service line with 4 on a mixed 1.2 invoice).
        $this->assertStringNotContainsString('η αιτία είναι', VatExemptionGuidance::typeConflict('1.2', 4)['message']);
    }

    public function test_types_outside_the_sales_classes_are_not_judged(): void
    {
        foreach (['5.1', '5.2', '11.1', '11.2', '3.1', '', null] as $type) {
            foreach (Codes::VAT_EXEMPTION_CATEGORIES as $code) {
                $this->assertNull(VatExemptionGuidance::typeConflict($type, $code), "{$code} on ".var_export($type, true));
            }
        }
        $this->assertNull(VatExemptionGuidance::typeConflict('2.2', null));
        $this->assertNull(VatExemptionGuidance::typeConflict('2.2', ''));
    }

    public function test_every_conflict_rule_uses_real_codes_and_types(): void
    {
        $seen = [];
        foreach (VatExemptionGuidance::TYPE_CONFLICTS as $rule) {
            $this->assertContains($rule['code'], Codes::VAT_EXEMPTION_CATEGORIES);
            $this->assertContains($rule['level'], [VatExemptionGuidance::CONFLICT_BLOCK, VatExemptionGuidance::CONFLICT_WARN]);
            foreach ($rule['types'] as $type) {
                // Only the types whose counterpart class is fixed are judged.
                $this->assertNotNull(Codes::counterpartCountryClass($type), $type);
                // One verdict per (code, type) — a later rule would be dead.
                $this->assertArrayNotHasKey($rule['code'].'@'.$type, $seen, "duplicate {$rule['code']} on {$type}");
                $seen[$rule['code'].'@'.$type] = true;
            }
        }
    }
}
