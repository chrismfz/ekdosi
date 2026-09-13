<?php

namespace Tests\Feature\EInvoice;

use App\Models\Invoice;
use App\Services\EInvoice\MovementHeaderBuilder;
use Firebed\AadeMyData\Enums\MovePurpose;
use Firebed\AadeMyData\Models\InvoiceHeader;
use Tests\TestCase;

/**
 * Combined ΤΔΑ — Slice 3d-c: the shared movement-header field-setter that both issue
 * paths (DeliveryNoteSubmitter 9.x + AadeInvoiceDocument ΤΔΑ 1.1) now route through,
 * so they can't drift on the header again. The byte-identical output of BOTH paths is
 * proven by DeliveryNoteSubmitterTest + CombinedTdaPayloadTest; this locks the shared
 * setter directly — especially the dispatchTime `H:i:s` (the exact drift the 3b review
 * caught). No DB: applyCommon only reads the movable's cast attributes.
 */
class MovementHeaderBuilderTest extends TestCase
{
    public function test_apply_common_sets_move_purpose_dispatch_hms_and_vehicle(): void
    {
        $doc = new Invoice(['dispatch_at' => '2026-09-13 14:30:05', 'vehicle_number' => 'ΙΑΒ1234']);
        $header = new InvoiceHeader;

        MovementHeaderBuilder::applyCommon($header, 8, $doc);

        $this->assertSame(MovePurpose::from(8), $header->getMovePurpose());
        $this->assertSame('2026-09-13', $header->getDispatchDate());
        $this->assertSame('14:30:05', $header->getDispatchTime()); // H:i:s, NOT H:i
        $this->assertSame('ΙΑΒ1234', $header->getVehicleNumber());
    }

    public function test_apply_common_omits_dispatch_and_vehicle_when_absent(): void
    {
        $doc = new Invoice(['dispatch_at' => null, 'vehicle_number' => null]);
        $header = new InvoiceHeader;

        MovementHeaderBuilder::applyCommon($header, 1, $doc);

        $this->assertSame(MovePurpose::from(1), $header->getMovePurpose());
        $this->assertNull($header->getDispatchDate());
        $this->assertNull($header->getDispatchTime());
        $this->assertNull($header->getVehicleNumber());
    }
}
