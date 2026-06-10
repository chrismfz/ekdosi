<?php

namespace App\Support\Peppol;

/**
 * Maps a line's VAT rate + the seller/buyer countries to an EN 16931 VAT
 * category code (BT-151) — the PEPPOL equivalent of the myDATA §8.x VAT
 * category. Country-agnostic (works for an Estonian seller, unlike the
 * GR-centric App\Support\MyData\ReverseCharge): the seller's OWN country is
 * "domestic", any other EU state is intra-community, the rest is export.
 *
 * Codes (UNCL5305):
 *   S  — Standard rated (rate > 0)
 *   Z  — Zero rated (domestic 0%)
 *   K  — VAT exempt for intra-community supply of goods/services (EU, reverse charge)
 *   G  — Free export item, VAT not charged (outside the EU)
 *   E  — Exempt from VAT
 *   AE — VAT Reverse charge
 *
 * Estonia applies no national CIUS beyond EN 16931, so plain category codes
 * suffice. Refinement (goods K vs services AE; explicit E exemptions) is a
 * follow-up once real cross-border cases appear.
 */
class PeppolVatCategory
{
    /** All 27 EU member states (ISO-3166-1 alpha-2), INCLUDING the seller's own. */
    public const EU_MEMBERS = [
        'AT', 'BE', 'BG', 'CY', 'CZ', 'DE', 'DK', 'EE', 'ES', 'FI', 'FR',
        'GR', 'HR', 'HU', 'IE', 'IT', 'LT', 'LU', 'LV', 'MT', 'NL', 'PL',
        'PT', 'RO', 'SE', 'SI', 'SK',
    ];

    /**
     * @return array{category: string, rate: float, exemptionReasonCode: ?string, exemptionReason: ?string}
     */
    public static function resolve(float $rate, string $sellerCountry, ?string $buyerCountry, bool $buyerHasVat): array
    {
        $seller = self::normalize($sellerCountry);
        $buyer = $buyerCountry === null || trim($buyerCountry) === '' ? $seller : self::normalize($buyerCountry);

        // A positive rate is always standard-rated, regardless of geography.
        if ($rate > 0) {
            return self::row('S', $rate);
        }

        // 0% — the geography decides which exemption applies.
        $domestic = $buyer === $seller;
        if ($domestic) {
            return self::row('Z', 0.0);
        }

        $buyerInEu = in_array($buyer, self::EU_MEMBERS, true);
        if ($buyerInEu && $buyerHasVat) {
            // Intra-community supply (reverse charge at the buyer).
            return self::row('K', 0.0, 'VATEX-EU-IC', 'Intra-community supply');
        }

        if (! $buyerInEu) {
            // Export outside the EU.
            return self::row('G', 0.0, 'VATEX-EU-G', 'Export outside the EU');
        }

        // EU buyer without a VAT id (B2C) at 0% — treat as zero-rated; a real
        // distance-sale case would charge the seller's domestic rate (refine later).
        return self::row('Z', 0.0);
    }

    private static function normalize(string $country): string
    {
        $c = strtoupper(trim($country));

        return $c === 'EL' ? 'GR' : $c;   // VIES uses EL for Greece
    }

    /**
     * @return array{category: string, rate: float, exemptionReasonCode: ?string, exemptionReason: ?string}
     */
    private static function row(string $category, float $rate, ?string $code = null, ?string $reason = null): array
    {
        return ['category' => $category, 'rate' => $rate, 'exemptionReasonCode' => $code, 'exemptionReason' => $reason];
    }
}
