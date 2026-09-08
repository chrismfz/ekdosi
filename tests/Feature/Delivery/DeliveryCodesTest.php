<?php

namespace Tests\Feature\Delivery;

use App\Support\MyData\DeliveryCodes;
use Tests\TestCase;

/**
 * The e-transport code tables + the "blocked move purposes" policy (§8.14
 * changelog: 6/15/16/17/18 no longer transmittable).
 */
class DeliveryCodesTest extends TestCase
{
    public function test_blocked_move_purposes_are_excluded_from_options(): void
    {
        $options = DeliveryCodes::movePurposeOptions();

        foreach (DeliveryCodes::BLOCKED_MOVE_PURPOSES as $blocked) {
            $this->assertArrayNotHasKey($blocked, $options, "move purpose $blocked must not be offered");
        }
        // Still offers the everyday ones, incl. the MYIP fallbacks 8 / 19.
        $this->assertArrayHasKey(1, $options);   // Πώληση
        $this->assertArrayHasKey(8, $options);   // Ενδοδιακίνηση
        $this->assertArrayHasKey(19, $options);  // Λοιπές Διακινήσεις
    }

    public function test_is_move_purpose_allowed(): void
    {
        $this->assertTrue(DeliveryCodes::isMovePurposeAllowed(8));
        $this->assertTrue(DeliveryCodes::isMovePurposeAllowed(1));
        $this->assertFalse(DeliveryCodes::isMovePurposeAllowed(18), 'Διακίνηση Παγίων is blocked');
        $this->assertFalse(DeliveryCodes::isMovePurposeAllowed(6));
        $this->assertFalse(DeliveryCodes::isMovePurposeAllowed(999), 'unknown code');
    }

    public function test_labels_resolve_from_firebed(): void
    {
        $this->assertSame('Ενδοδιακίνηση', DeliveryCodes::movePurposeLabel(8));
        // A blocked code still has a (informational) label — only its
        // transmission/offering is blocked.
        $this->assertNotNull(DeliveryCodes::movePurposeLabel(18));
        $this->assertNull(DeliveryCodes::movePurposeLabel(null));

        $this->assertNotEmpty(DeliveryCodes::transportTypeOptions());
        $this->assertNotNull(DeliveryCodes::transportTypeLabel(1));
        $this->assertNotEmpty(DeliveryCodes::packagingTypeOptions());
        $this->assertNotNull(DeliveryCodes::packagingTypeLabel(1));
        $this->assertNotNull(DeliveryCodes::deliveryStatusLabel(1));
    }
}
