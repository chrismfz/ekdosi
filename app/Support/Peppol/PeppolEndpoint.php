<?php

namespace App\Support\Peppol;

use App\Models\Company;
use App\Models\Customer;
use Einvoicing\Identifier;

/**
 * Derives a party's PEPPOL electronic address (BT-34 seller / BT-49 buyer) — the
 * network participant identifier = a value + an EAS scheme (ISO/IEC 6523).
 *
 * For a buyer we honour an explicit `customers.peppol_endpoint` (operator-entered,
 * authoritative — the recipient tells you their address), accepting either a bare
 * value or a "scheme:value" form. Otherwise we derive one from the VAT id using
 * the country's default scheme (e.g. EE VAT → 9931). The seller is always derived
 * from the tenant's own tax id.
 *
 * Scheme map is intentionally small (the countries we actually issue from/to);
 * extend as needed — full list at https://docs.peppol.eu/poacc/billing/3.0/codelist/eas/
 */
class PeppolEndpoint
{
    /** country ISO → default EAS scheme for a VAT-based participant id. */
    private const VAT_SCHEME = [
        'EE' => '9931', // Estonia VAT
        'GR' => '9933', // Greece VAT
        'FI' => '0216', // (OVT also exists; VAT-based default)
        'DE' => '9930',
        'FR' => '9957',
        'IT' => '9906',
        'NL' => '9944',
        'SE' => '9955',
    ];

    public static function forSeller(Company $company): ?Identifier
    {
        return self::fromVat($company->afm, $company->country_code);
    }

    public static function forBuyer(Customer $customer): ?Identifier
    {
        $explicit = trim((string) ($customer->peppol_endpoint ?? ''));
        if ($explicit !== '') {
            // "scheme:value" → split; bare → derive the scheme from the country/VAT prefix.
            if (preg_match('/^(\d{4}):(.+)$/', $explicit, $m)) {
                return new Identifier($m[2], $m[1]);
            }
            $scheme = self::schemeFor($customer->country)
                ?? self::schemeFor($customer->vat_vies ? substr((string) $customer->vat_vies, 0, 2) : null);

            // A scheme-less EndpointID is INVALID PEPPOL (BT-49 requires schemeID),
            // and the library's validate() won't catch it — omit rather than emit garbage.
            return $scheme === null ? null : new Identifier($explicit, $scheme);
        }

        return self::fromVat($customer->vat_vies ?: $customer->afm, $customer->country);
    }

    private static function fromVat(?string $vat, ?string $country): ?Identifier
    {
        $vat = strtoupper(trim((string) $vat));
        if ($vat === '') {
            return null;
        }

        // 'GR' is never a valid VAT prefix (Greece uses 'EL', EN 16931 BR-CO-9) —
        // correct a mistyped value so the participant id matches its 9933 (GR:VAT)
        // scheme. (The bare-vs-EL-prefixed format choice for scheme 9933 is a
        // Phase-2 transport decision; here we only reject the invalid prefix.)
        if (preg_match('/^GR\d/', $vat)) {
            $vat = 'EL'.substr($vat, 2);
        }

        // A prefixed VAT (EE123…) implies its own country scheme.
        if (preg_match('/^([A-Z]{2})(.+)$/', $vat, $m)) {
            $scheme = self::schemeFor($m[1]);
        } else {
            $scheme = self::schemeFor($country);
        }

        // No resolvable EAS scheme → omit the endpoint (a scheme-less BT-34/49 is
        // invalid PEPPOL and slips past the library's validate()). The seller/buyer
        // address is still complete; the missing endpoint surfaces in Phase-2's real
        // Schematron/AP validation, not as malformed XML now.
        return $scheme === null ? null : new Identifier($vat, $scheme);
    }

    private static function schemeFor(?string $country): ?string
    {
        $c = strtoupper(trim((string) $country));
        $c = $c === 'EL' ? 'GR' : $c;

        return self::VAT_SCHEME[$c] ?? null;
    }
}
