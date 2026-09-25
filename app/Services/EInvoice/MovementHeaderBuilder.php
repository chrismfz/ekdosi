<?php

namespace App\Services\EInvoice;

use App\Contracts\MovableDocument;
use Carbon\Carbon;
use Firebed\AadeMyData\Enums\MovePurpose;
use Firebed\AadeMyData\Models\InvoiceHeader;

/**
 * The issue-time movement-header fields shared IDENTICALLY by both e-transport
 * issue paths — `DeliveryNoteSubmitter` (a money-less 9.x δελτίο) and
 * `AadeInvoiceDocument::applyMovementHeader` (a monetary 1.1 Combined ΤΔΑ). Both
 * set the SAME `InvoiceHeader` fields the same way; the 3b review found the two
 * copies had already drifted once (`dispatchTime` `H:i` vs `H:i:s`), so this is the
 * single home that keeps them in lock-step (Combined ΤΔΑ, Slice 3d-c).
 *
 * SHARED here (byte-identical for valid input): `movePurpose`, the planned
 * `dispatchDate`/`dispatchTime` (H:i:s), and `vehicleNumber`.
 *
 * NOT shared — deliberately left in each caller because it GENUINELY differs:
 *   - move_purpose VALIDATION + the missing-purpose / purpose-19-title errors: each
 *     caller throws with its own document-specific Greek message («δελτίο …» vs «ΤΔΑ …»)
 *     and its own pre-checks (the 9.x path also rejects the AADE-deprecated §8.14 set);
 *   - the `otherDeliveryNoteHeader` (loading/delivery addresses): a 9.x note MANDATES
 *     them (`DeliveryNoteSubmitter::buildDeliveryHeader` hard-fails on a blank), while a
 *     ΤΔΑ's form guarantees them so `AadeInvoiceDocument` builds leniently (omit-if-empty);
 *   - the 9.x-only `thirdPartyCollection`, and the ΤΔΑ-only `isDeliveryNote` /
 *     `withoutDigitalTransportTracking` / goods-type guard.
 * Field-setter call ORDER is irrelevant — firebed serializes the header in a fixed
 * schema order — so a caller may invoke this before or after its own setters.
 */
class MovementHeaderBuilder
{
    /**
     * Set the shared movement fields on $header. $movePurpose is the already-VALIDATED
     * §8.14 code (the caller owns the missing/deprecated-purpose error); the purpose-19
     * title stays with the caller (its message + its own validation).
     */
    public static function applyCommon(InvoiceHeader $header, int $movePurpose, MovableDocument $doc): void
    {
        $header->setMovePurpose(MovePurpose::from($movePurpose));

        // Planned dispatch date/time. hh:mm:ss is firebed's documented format — the ONE
        // place it lives now, so the 9.x and ΤΔΑ paths can never drift on it again.
        if ($doc->dispatch_at !== null) {
            $dispatch = Carbon::parse($doc->dispatch_at);
            $header->setDispatchDate($dispatch->toDateString());
            $header->setDispatchTime($dispatch->format('H:i:s'));
        }

        if (filled($doc->vehicle_number)) {
            $header->setVehicleNumber((string) $doc->vehicle_number);
        }

        // «Μη υπόχρεος λήπτης» (private person / no ERP): with our own vehicle («ίδια
        // μέσα») our FULL outcome then closes the movement (AADE Completed) instead of
        // waiting for a recipient QR scan that will never come. Never together with a
        // ΤΔΑ's withoutDigitalTransportTracking — AADE [290] forbids the combination
        // (and an untracked note has no outcome to close anyway).
        if ((bool) $doc->non_obligated_recipient && ! (bool) ($doc->without_digital_transport_tracking ?? false)) {
            $header->setNonObligatedRecipient(true);
        }
    }
}
