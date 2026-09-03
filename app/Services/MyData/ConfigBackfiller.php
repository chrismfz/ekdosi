<?php

namespace App\Services\MyData;

use App\Models\Company;
use App\Models\PaymentMethod;
use App\Models\VatCategory;
use App\Support\GreekText;
use App\Support\MyData\Codes;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * One-shot, idempotent backfill that brings an IMPORTED (legacy-ETL) tenant up to
 * the fresh-setup myDATA config defaults. A tenant seeded by MyDataLookupSeeder is
 * correct out of the box; a tenant migrated from Firebird is not, because the
 * legacy DB had no field for the §8.3 exemption reason or the §8.12 payment type —
 * so those imported rows land NULL and fail/warn the preflight ([217] / type-3
 * fallback). This closes exactly that gap, without touching anything already set.
 *
 * Scope: AADE-filing tenants only (gr-mydata / gr-provider). §8.3 and §8.12 are
 * Greek AADE codes, meaningless for an ee-peppol / none tenant, so a non-AADE
 * company is a no-op (guarded in {@see plan()} so ANY caller is safe).
 *
 * Two backfills, deliberately different in confidence:
 *  - §8.12 on a payment method with no type → a KEYWORD SUGGESTION, matched by
 *    whole word (Latin) / stem prefix (Greek) against the tokenised name, so 'pos'
 *    does not match «deposit»/«postal» and 'card' beats 'credit' on «credit card».
 *    A name that matches nothing is left NULL for the operator (never a blind
 *    type-3). Low stakes (AADE accepts type 3), so applied on --execute.
 *  - §8.3 on a reason-less 0% VAT category → the fresh-setup default
 *    ({@see Codes::ZERO_RATE_SEED}). This is a LEGAL exemption reason and cannot be
 *    derived from the category alone (intra-EU vs export vs reverse-charge), so it
 *    is written ONLY when the caller opts in ($writeExemption) AND there is exactly
 *    ONE reason-less 0% category (the single-0% invariant a fresh seed keeps); 2+
 *    are reported ambiguous and never auto-set (the MYD-009 «refuse rather than
 *    guess» discipline). Default runs REPORT the gap but do not write it.
 *
 * Idempotent: only NULL rows are ever touched, so a re-run is a no-op. CLI/queue
 * safe: every query filters `company_id` explicitly (no ambient CompanyContext).
 */
class ConfigBackfiller
{
    /** einvoice_provider values that file to AADE (and so use §8.3 / §8.12 codes). */
    public const AADE_PROVIDERS = ['gr-mydata', 'gr-provider'];

    /**
     * §8.12 suggestion rules, in PRIORITY order (first match wins): [keyword, type,
     * wholeWord]. Greek stems match a token by PREFIX (inflected forms); Latin
     * keywords match a WHOLE word (so 'pos'∉«postal», and 'card' — placed before
     * 'credit' — wins «credit card»).
     *
     * @var list<array{0:string, 1:int, 2:bool}>
     */
    public const PAYMENT_RULES = [
        ['iris', 8, true],
        ['paypal', 7, true],
        ['stripe', 7, true],
        ['viva', 7, true],
        ['καρτ', 7, false],   // κάρτα/κάρτες/καρτών — stem before the ending vowel
        ['card', 7, true],
        ['pos', 7, true],
        ['visa', 7, true],
        ['mastercard', 7, true],
        ['banking', 6, true],   // web/internet/home/mobile banking — before generic 'bank'
        ['ebanking', 6, true],
        ['καταθεσ', 1, false],
        ['εμβασμα', 1, false],
        ['τραπεζ', 1, false],
        ['iban', 1, true],
        ['bank', 1, true],
        ['transfer', 1, true],
        ['επιταγ', 4, false],
        ['cheque', 4, true],
        ['πιστωσ', 5, false],
        ['credit', 5, true],
        ['μετρητ', 3, false],
        ['cash', 3, true],
    ];

    /** The fresh-setup §8.3 default for a reason-less 0% category — derived from the seed, never drifts. */
    public static function zeroRateDefaultExemption(): int
    {
        return (int) (Codes::ZERO_RATE_SEED[0]['code'] ?? 4);
    }

    /**
     * Compute the changes WITHOUT writing (dry-run). A non-AADE tenant is a no-op.
     * `vat` is the single-category §8.3 default CANDIDATE (written only on an opted-in
     * apply); `vat_ambiguous` is 2+ reason-less 0% categories that need the operator.
     *
     * @return array{
     *   vat: list<array{id:int, label:string, to:int}>,
     *   vat_ambiguous: list<array{id:int, label:string}>,
     *   payments: list<array{id:int, description:string, to:int, keyword:string}>,
     *   payments_unmatched: list<array{id:int, description:string}>
     * }
     */
    public function plan(Company $company): array
    {
        if (! in_array($company->einvoice_provider, self::AADE_PROVIDERS, true)) {
            return ['vat' => [], 'vat_ambiguous' => [], 'payments' => [], 'payments_unmatched' => []];
        }

        $zeroRate = $this->reasonlessZeroRateCategories($company);
        $vat = [];
        $vatAmbiguous = [];
        if ($zeroRate->count() === 1) {
            $cat = $zeroRate->first();
            $vat[] = ['id' => (int) $cat->id, 'label' => (string) $cat->description, 'to' => self::zeroRateDefaultExemption()];
        } elseif ($zeroRate->count() > 1) {
            foreach ($zeroRate as $cat) {
                $vatAmbiguous[] = ['id' => (int) $cat->id, 'label' => (string) $cat->description];
            }
        }

        $payments = [];
        $unmatched = [];
        foreach ($this->unmappedPaymentMethods($company) as $method) {
            $type = $this->suggestPaymentType((string) $method->description);
            if ($type === null) {
                $unmatched[] = ['id' => (int) $method->id, 'description' => (string) $method->description];

                continue;
            }
            $payments[] = [
                'id' => (int) $method->id,
                'description' => (string) $method->description,
                'to' => $type['code'],
                'keyword' => $type['keyword'],
            ];
        }

        return ['vat' => $vat, 'vat_ambiguous' => $vatAmbiguous, 'payments' => $payments, 'payments_unmatched' => $unmatched];
    }

    /**
     * Apply the plan and return the SAME shape describing what was considered. The
     * §8.12 payment suggestions are always written; the §8.3 exemption default is
     * written ONLY when $writeExemption is true (a legal code, opted-in explicitly).
     * One transaction, only NULL columns, so a partial failure rolls back and a
     * re-run is a no-op.
     *
     * @return array{
     *   vat: list<array{id:int, label:string, to:int}>,
     *   vat_ambiguous: list<array{id:int, label:string}>,
     *   payments: list<array{id:int, description:string, to:int, keyword:string}>,
     *   payments_unmatched: list<array{id:int, description:string}>
     * }
     */
    public function apply(Company $company, bool $writeExemption = false): array
    {
        $plan = $this->plan($company);

        DB::transaction(function () use ($company, $plan, $writeExemption) {
            if ($writeExemption) {
                foreach ($plan['vat'] as $row) {
                    VatCategory::query()
                        ->where('company_id', $company->getKey())
                        ->whereKey($row['id'])
                        ->whereNull('vat_exemption_category')
                        ->update(['vat_exemption_category' => $row['to']]);
                }
            }

            foreach ($plan['payments'] as $row) {
                PaymentMethod::query()
                    ->where('company_id', $company->getKey())
                    ->whereKey($row['id'])
                    ->whereNull('mydata_payment_type')
                    ->update(['mydata_payment_type' => $row['to']]);
            }
        });

        return $plan;
    }

    /** @return Collection<int, VatCategory> */
    private function reasonlessZeroRateCategories(Company $company)
    {
        return VatCategory::query()
            ->where('company_id', $company->getKey())
            ->whereNull('vat_exemption_category')
            ->where('rate', 0)   // rate is NOT NULL (default 0) — a 0% category is exactly rate 0
            // Skip myDATA vatCategory 8 (εγγραφές χωρίς ΦΠΑ) — it legitimately needs no §8.3 reason.
            ->where(fn ($q) => $q->whereNull('mydata_vat_category')->orWhere('mydata_vat_category', '!=', 8))
            ->get();
    }

    /** @return Collection<int, PaymentMethod> */
    private function unmappedPaymentMethods(Company $company)
    {
        return PaymentMethod::query()
            ->where('company_id', $company->getKey())
            ->whereNull('mydata_payment_type')
            ->get();
    }

    /**
     * Suggest a §8.12 type from a free-text method name, or null when nothing
     * matches. Tokenises the accent-folded name, then Latin keywords match a WHOLE
     * token and Greek stems match a token by prefix. Returns the matched keyword too
     * so the operator can see WHY.
     *
     * @return array{code:int, keyword:string}|null
     */
    public function suggestPaymentType(string $description): ?array
    {
        $tokens = $this->tokens($description);
        if ($tokens === []) {
            return null;
        }

        foreach (self::PAYMENT_RULES as [$keyword, $code, $wholeWord]) {
            foreach ($tokens as $token) {
                $hit = $wholeWord ? ($token === $keyword) : str_starts_with($token, $keyword);
                if ($hit && Codes::paymentMethodExists($code)) {
                    return ['code' => $code, 'keyword' => $keyword];
                }
            }
        }

        return null;
    }

    /**
     * Accent-fold + split a description into letter/digit tokens (so keyword rules
     * can match whole words / stems, not arbitrary infixes).
     *
     * @return list<string>
     */
    private function tokens(string $description): array
    {
        $parts = preg_split('/[^\p{L}\p{N}]+/u', GreekText::fold($description)) ?: [];

        return array_values(array_filter($parts, fn (string $t) => $t !== ''));
    }
}
