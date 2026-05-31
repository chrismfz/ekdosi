<?php

namespace App\Support\MyData;

use App\Models\Customer;

/**
 * The single predicate for "is this an EU intra-community supply that should be
 * invoiced at 0% with reverse charge?" — so the rule lives in ONE place instead
 * of being re-implemented at each UI hint / future auto-default.
 *
 * Rule: the counterparty is in an EU member state OTHER than Greece and carries
 * a VAT id. (We don't VIES-validate here — that's a separate, networked step;
 * this is the cheap structural check that drives the operator hint.) The
 * matching AADE §8.3 exemption reason is code 16 (άρθρο 45, ex-«39α»),
 * Codes::VAT_EXEMPTION_INTRACOMMUNITY.
 */
class ReverseCharge
{
    /**
     * EU member-state ISO-3166-1 alpha-2 codes (GR excluded — Greek customers
     * are domestic, never reverse-charge). XI = Northern Ireland.
     *
     * @var list<string>
     */
    public const EU_COUNTRIES = [
        'AT', 'BE', 'BG', 'CY', 'CZ', 'DE', 'DK', 'EE', 'ES', 'FI', 'FR',
        'HR', 'HU', 'IE', 'IT', 'LT', 'LU', 'LV', 'MT', 'NL', 'PL', 'PT', 'RO',
        'SE', 'SI', 'SK', 'XI',
    ];

    /** True when $country is an EU member state other than Greece. */
    public static function isEuNonGreek(?string $country): bool
    {
        $c = strtoupper(trim((string) $country));
        if ($c === 'EL') {
            $c = 'GR';   // VIES code for Greece
        }

        return in_array($c, self::EU_COUNTRIES, true);
    }

    /**
     * Should this customer's invoice default to reverse charge (0%)?
     * EU-non-GR country + a VAT id present (afm or vat_vies).
     */
    public static function appliesTo(Customer $customer): bool
    {
        if (! self::isEuNonGreek($customer->country)) {
            return false;
        }

        $hasVat = trim((string) ($customer->vat_vies ?? '')) !== ''
            || trim((string) ($customer->afm ?? '')) !== '';

        return $hasVat;
    }
}
