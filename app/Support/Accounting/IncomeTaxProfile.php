<?php

namespace App\Support\Accounting;

use App\Models\Company;

/**
 * The per-tenant knobs of the income-tax ESTIMATE («Φορολογικά» page), read from
 * `companies.income_tax_profile` (JSON) with the defaults for a Greek ΟΕ/ΕΕ/ΙΚΕ/ΑΕ:
 *
 *   - rate            : φορολογικός συντελεστής % on the taxable profit (22).
 *   - prepayment_rate : προκαταβολή % of the tax for the next year (80). A new
 *                       business pays half of it for its first years — the operator
 *                       lowers the rate, we don't encode the rule.
 *   - assessed_prepayments : {year: amount} — the προκαταβολή actually ΒΕΒΑΙΩΘΗΚΕ
 *                       for that year (copied from the εκκαθαριστικό). The ONLY
 *                       prepayment the estimate subtracts — unset counts as 0.
 *   - expected_partner_insurance : the partners' yearly ΕΦΚΑ (εισφορές αυτοαπασχολούμενων,
 *                       Ε3 E3_585_007) the accountant posts only at the year's close.
 *                       The running-year PROJECTION adds what of it hasn't appeared
 *                       yet, so the estimate doesn't overstate the tax until December.
 *
 * Rates are settings, not constants in code: tax law moves, and the accountant
 * is the authority. Nothing here is legal advice — it drives a planning figure.
 */
final readonly class IncomeTaxProfile
{
    public const DEFAULT_RATE = 22.0;

    public const DEFAULT_PREPAYMENT_RATE = 80.0;

    /** @param  array<int, float>  $assessedPrepayments */
    public function __construct(
        public float $rate = self::DEFAULT_RATE,
        public float $prepaymentRate = self::DEFAULT_PREPAYMENT_RATE,
        public array $assessedPrepayments = [],
        public float $expectedPartnerInsurance = 0.0,
    ) {}

    public static function for(Company $company): self
    {
        $p = is_array($company->income_tax_profile) ? $company->income_tax_profile : [];

        $assessed = [];
        foreach ((array) ($p['assessed_prepayments'] ?? []) as $year => $amount) {
            if (is_numeric($year) && is_numeric($amount)) {
                $assessed[(int) $year] = round((float) $amount, 2);
            }
        }

        return new self(
            rate: self::pct($p['rate'] ?? null, self::DEFAULT_RATE),
            prepaymentRate: self::pct($p['prepayment_rate'] ?? null, self::DEFAULT_PREPAYMENT_RATE),
            assessedPrepayments: $assessed,
            expectedPartnerInsurance: is_numeric($p['expected_partner_insurance'] ?? null) && (float) $p['expected_partner_insurance'] > 0
                ? round((float) $p['expected_partner_insurance'], 2) : 0.0,
        );
    }

    /** The JSON shape stored back on the company. */
    public function toArray(): array
    {
        $assessed = $this->assessedPrepayments;
        ksort($assessed);

        return [
            'rate' => $this->rate,
            'prepayment_rate' => $this->prepaymentRate,
            'assessed_prepayments' => array_map(fn ($v) => round((float) $v, 2), $assessed),
            'expected_partner_insurance' => round($this->expectedPartnerInsurance, 2),
        ];
    }

    public function assessedPrepaymentFor(int $year): ?float
    {
        return $this->assessedPrepayments[$year] ?? null;
    }

    private static function pct(mixed $v, float $default): float
    {
        return is_numeric($v) && (float) $v >= 0 && (float) $v <= 100 ? (float) $v : $default;
    }
}
