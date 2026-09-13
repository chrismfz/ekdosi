<?php

namespace App\Services\Delivery;

use Firebed\AadeMyData\Models\DigitalGoodsMovement\DeliveryEvent;

/**
 * Serialises firebed `DeliveryEvent` models into a lightweight, JSON-safe,
 * display-only array for the `inbound_delivery_notes.lifecycle` column.
 *
 * Shared by {@see InboundDeliveryFetcher} (parsing the RequestDocs
 * `deliveryLifecycle`) and {@see InboundDeliveryService} (parsing the
 * RequestDeliveryNoteStatus `lifecycleHistory`) so the two entry points can
 * never drift into two different snapshot shapes.
 */
final class DeliveryEventSnapshot
{
    /**
     * @param  iterable<mixed>|null  $events  a firebed DeliveryLifecycle (iterable of DeliveryEvent) or DeliveryEvent[]
     * @return list<array<string, mixed>>|null null when there are no events
     */
    public static function fromEvents(?iterable $events): ?array
    {
        if ($events === null) {
            return null;
        }

        $out = [];
        foreach ($events as $event) {
            if (! $event instanceof DeliveryEvent) {
                continue;
            }

            $out[] = array_filter([
                'type' => $event->getEventType()?->value,
                'timestamp' => $event->getEventTimestamp(),
                'actor_vat' => $event->getActorVat(),
                'mark' => $event->getMark(),
                'details' => self::details($event),
            ], static fn ($v) => $v !== null);
        }

        return $out === [] ? null : $out;
    }

    /**
     * @return array<string, mixed>|null
     */
    private static function details(DeliveryEvent $event): ?array
    {
        if ($transport = $event->getTransportDetails()) {
            return array_filter([
                'vehicle_number' => $transport->getVehicleNumber(),
                'carrier_vat' => $transport->getCarrierVatNumber(),
                'transport_type' => $transport->getTransportType()?->value,
                'timestamp' => $transport->getTimestamp(),
            ], static fn ($v) => $v !== null);
        }

        if ($outcome = $event->getOutcomeDetails()) {
            return array_filter([
                'outcome' => $outcome->getOutcome()?->value,
                'delivered_without_recipient' => $outcome->getDeliveredWithoutRecipient(),
            ], static fn ($v) => $v !== null);
        }

        if ($rejection = $event->getRejectionDetails()) {
            return array_filter(['reason' => $rejection->getReason()], static fn ($v) => $v !== null);
        }

        return null;
    }
}
