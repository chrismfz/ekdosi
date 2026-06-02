<?php

namespace Tests\Feature\Delivery;

use App\Support\MyData\DeliveryCodes;
use App\Support\MyData\DeliveryGuidance;
use Tests\TestCase;

/**
 * The operator guidance layer: every plain-Greek scenario must map to a move
 * purpose AADE still accepts, and the helper texts must be wired.
 */
class DeliveryGuidanceTest extends TestCase
{
    public function test_every_scenario_maps_to_an_allowed_move_purpose(): void
    {
        $this->assertNotEmpty(DeliveryGuidance::SCENARIOS);

        foreach (DeliveryGuidance::SCENARIOS as $key => $scenario) {
            $this->assertArrayHasKey('move_purpose', $scenario, "scenario $key");
            $this->assertTrue(
                DeliveryCodes::isMovePurposeAllowed($scenario['move_purpose']),
                "scenario '$key' maps to move purpose {$scenario['move_purpose']} which AADE blocks",
            );
            $this->assertNotEmpty($scenario['label']);
            $this->assertNotEmpty($scenario['hint']);
        }
    }

    public function test_scenario_lookup_and_options(): void
    {
        $options = DeliveryGuidance::scenarioOptions();
        $this->assertArrayHasKey('internal', $options);
        $this->assertSame(DeliveryGuidance::SCENARIOS['internal']['label'], $options['internal']);

        $this->assertSame(8, DeliveryGuidance::scenario('internal')['move_purpose']);   // Ενδοδιακίνηση (declared establishment)
        $this->assertSame(14, DeliveryGuidance::scenario('colocation')['move_purpose']); // Αποθήκευση σε Τρίτους (undeclared colo)
        $this->assertSame(7, DeliveryGuidance::scenario('repair')['move_purpose']);      // Επεξεργασία (send for service)
        $this->assertSame(5, DeliveryGuidance::scenario('return')['move_purpose']);      // Επιστροφή

        $this->assertNull(DeliveryGuidance::scenario('nonsense'));
    }

    public function test_field_help_is_wired(): void
    {
        $this->assertNotNull(DeliveryGuidance::fieldHelp('move_purpose'));
        $this->assertNotNull(DeliveryGuidance::fieldHelp('loading_address'));
        $this->assertNull(DeliveryGuidance::fieldHelp('nonexistent_field'));
        $this->assertNotEmpty(DeliveryGuidance::INTRO);
    }
}
