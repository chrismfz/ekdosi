<?php

namespace App\Support\MyData;

use Firebed\AadeMyData\Enums\DigitalGoodsMovement\DeliveryStatus;
use Firebed\AadeMyData\Enums\DigitalGoodsMovement\PackagingType;
use Firebed\AadeMyData\Enums\DigitalGoodsMovement\TransportType;
use Firebed\AadeMyData\Enums\MovePurpose;
use Firebed\AadeMyData\Enums\UnitMeasurement;

/**
 * The myDATA code tables for Παραστατικά Διακίνησης (e-transport): σκοπός
 * διακίνησης (§8.14), τρόπος μεταφοράς, τύπος συσκευασίας (§8.23), κατάσταση
 * δελτίου (§8.22). Greek labels are delegated to the firebed enums (single
 * source); on top we bake the ONE policy firebed doesn't enforce — the move
 * purposes AADE no longer accepts for transmission.
 */
class DeliveryCodes
{
    /**
     * §8.14 changelog: «Η αποστολή των σκοπών διακίνησης 6, 15, 16, 17, 18 δεν
     * είναι πλέον επιτρεπτή.» So 6 (Φύλαξη), 15 (Επιστροφή από Φύλαξη), 16
     * (Ανακύκλωση), 17 (Καταστροφή), **18 (Διακίνηση Παγίων)** must not be
     * offered/transmitted. (The own-equipment/fixed-asset move — e.g. moving an
     * owned server — uses 8 Ενδοδιακίνηση or 19 Λοιπές + a title instead.)
     */
    public const BLOCKED_MOVE_PURPOSES = [6, 15, 16, 17, 18];

    public static function isMovePurposeAllowed(int $code): bool
    {
        return MovePurpose::tryFrom($code) !== null
            && ! in_array($code, self::BLOCKED_MOVE_PURPOSES, true);
    }

    public static function movePurposeLabel(?int $code): ?string
    {
        return $code === null ? null : MovePurpose::tryFrom($code)?->label();
    }

    /**
     * value => "code — label" for a Filament Select, EXCLUDING the blocked
     * purposes so an operator can't pick a non-transmittable one.
     *
     * @return array<int, string>
     */
    public static function movePurposeOptions(): array
    {
        $out = [];
        foreach (MovePurpose::cases() as $case) {
            if (in_array($case->value, self::BLOCKED_MOVE_PURPOSES, true)) {
                continue;
            }
            $out[$case->value] = $case->value.' — '.$case->label();
        }

        return $out;
    }

    public static function transportTypeLabel(?int $code): ?string
    {
        return $code === null ? null : TransportType::tryFrom($code)?->label();
    }

    /** @return array<int, string> */
    public static function transportTypeOptions(): array
    {
        return self::optionsFromEnum(TransportType::cases());
    }

    public static function packagingTypeLabel(?int $code): ?string
    {
        return $code === null ? null : PackagingType::tryFrom($code)?->label();
    }

    /** @return array<int, string> */
    public static function packagingTypeOptions(): array
    {
        return self::optionsFromEnum(PackagingType::cases());
    }

    /** §8.22 — Greek label for a delivery-note status code. */
    public static function deliveryStatusLabel(?int $code): ?string
    {
        return $code === null ? null : DeliveryStatus::tryFrom($code)?->label();
    }

    /**
     * §8.13 — Greek label for a measurement-unit code (1–7), for the PDF/UI.
     * Returns null for unknown/out-of-range so the caller can fall back to the
     * raw value (mirrors the submitter's 1–7 clamp without forcing a default
     * here — display should never invent a unit the operator didn't pick).
     */
    public static function measurementUnitLabel(?int $code): ?string
    {
        return $code === null ? null : UnitMeasurement::tryFrom($code)?->label();
    }

    /**
     * @param  list<MovePurpose|TransportType|PackagingType|DeliveryStatus>  $cases
     * @return array<int, string>
     */
    private static function optionsFromEnum(array $cases): array
    {
        $out = [];
        foreach ($cases as $case) {
            $out[$case->value] = $case->value.' — '.$case->label();
        }

        return $out;
    }
}
