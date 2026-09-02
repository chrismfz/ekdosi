<?php

namespace App\Support\MyData;

use App\Models\Company;
use App\Models\Customer;
use App\Models\VatCategory;

/**
 * The single predicate for "is this an EU intra-community supply that should be
 * invoiced at 0% with reverse charge?" — so the rule lives in ONE place instead
 * of being re-implemented at each UI hint / future auto-default.
 *
 * Rule: the counterparty is in an EU member state OTHER than Greece and carries
 * a VAT id. (We don't VIES-validate here — that's a separate, networked step;
 * this is the cheap structural check that drives the operator hint + the 0%
 * auto-default.)
 *
 * The matching §8.3 exemption reason is NOT one fixed code — it depends on the
 * document (MYD-007): an intra-community SERVICE (type 2.2) is code 4 (άρθρο 18),
 * intra-community GOODS (type 1.2) is code 14 (άρθρο 33). So the reason is set
 * PER LINE from the invoice type (VatExemptionGuidance::recommendForType); this
 * class only decides the 0% RATE default. Code 16 (άρθρο 45) is a DOMESTIC
 * reverse-charge case, not the intra-community default — the old assumption this
 * docblock made was the MYD-007 bug.
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

    /**
     * Should new invoice lines for this customer DEFAULT to 0% (reverse charge)?
     *
     * True when BOTH hold:
     *   1. appliesTo($customer) — EU non-GR counterparty with a VAT id; and
     *   2. the tenant has AT LEAST ONE 0%-rate VatCategory carrying a §8.3
     *      exemption reason — so 0% is a configured, fileable rate.
     *
     * MYD-007 relaxed this from «exactly one» to «at least one»: the per-line
     * exemption reason (set from the invoice type when a line defaults to 0%) is
     * now the source of truth, so several 0% categories are no longer ambiguous
     * and no longer disable the default. The operator can still change the per-line
     * VAT afterwards (mixed invoices); a domestic/GR customer is never affected.
     */
    public static function shouldDefaultZeroVat(Company $tenant, Customer $customer): bool
    {
        if (! self::appliesTo($customer)) {
            return false;
        }

        return VatCategory::query()
            ->where('company_id', $tenant->getKey())
            ->where('rate', 0)
            ->whereNotNull('vat_exemption_category')
            ->exists();
    }
}
