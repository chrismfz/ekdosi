<?php

namespace App\Support\MyData;

use App\Services\EInvoice\AadeInvoiceDocument;
use App\Support\GoLive\GoLiveCheckReport;

/**
 * MYD-006: the tenant's BUSINESS-ACTIVITY policy → the income-classification
 * BUCKET (§8.6 `categoryN_x`) its GOODS lines file under. Mirrors {@see
 * VatExemptionGuidance}: the rule is encoded ONCE here; the operator picks a
 * plain-Greek activity type and the filing path reads the mapping.
 *
 * WHY this exists: AADE's §8.6 income categories distinguish
 *   - category1_1 = «Εμπορεύματα» (goods bought to RESELL), from
 *   - category1_2 = «Προϊόντα» (the entity's OWN products), from
 *   - category1_3 = «Παροχή Υπηρεσιών» (services).
 * There is NO single default that is correct for every business: the seed
 * classifies goods types as category1_1 (the merchant assumption), which
 * mis-files a MANUFACTURER's own products (they are category1_2). The §8.5 E3
 * income CLASS (E3_561_001 wholesale vs E3_561_003 retail …) is channel-driven
 * and stays on the invoice type; only the goods BUCKET depends on the business.
 *
 * WHAT IS WIRED: {@see AadeInvoiceDocument::resolveIncomeClass}
 * consults `goodsCategoryFor()` for a goods line that would otherwise fall back
 * to the merchandise default (category1_1) with no product-category override — a
 * manufacturer's line becomes category1_2, a reseller's stays category1_1,
 * services/mixed/unset leave it unchanged. {@see GoLiveCheckReport}
 * FAILS the cutover until a policy is chosen. The Company form + CompanySettings
 * expose the picker with these labels/hints.
 *
 * A `services` tenant needs no goods policy (its lines file category1_3 from the
 * type); a `mixed` tenant configures the bucket PER product-category (Setup →
 * Κατηγορίες προϊόντων), so both return null here (no tenant-wide goods default).
 */
class ClassificationGuidance
{
    public const RESELLER = 'reseller';

    public const MANUFACTURER = 'manufacturer';

    public const SERVICES = 'services';

    public const MIXED = 'mixed';

    /**
     * The §8.6 bucket a goods line takes by default — «Εμπορεύματα» (the merchant
     * assumption baked into the seed / invoice-type defaults). It is BOTH the
     * reseller policy's goods category AND the sentinel the filing path keys the
     * policy substitution off ({@see AadeInvoiceDocument::resolveIncomeClass}); one
     * name so the two can't drift (if the seed default ever changes, change it here
     * and the guard follows — the MYD-006 defect can't silently resurface).
     */
    public const MERCHANDISE_DEFAULT = 'category1_1';

    /**
     * The business-activity policies. `goods_category` is the §8.6 bucket a GOODS
     * line files under when it has no per-product-category override; null = «no
     * tenant-wide goods default» (services has no goods; mixed configures per
     * product-category). `requires_per_category` flags that the operator must set
     * the bucket on each product category themselves.
     *
     * @var array<string, array{label: string, goods_category: ?string, requires_per_category: bool, hint: string}>
     */
    public const POLICIES = [
        self::RESELLER => [
            'label' => 'Μεταπωλητής / Έμπορος (πώληση εμπορευμάτων τρίτων)',
            'goods_category' => self::MERCHANDISE_DEFAULT, // category1_1 — Εμπορεύματα
            'requires_per_category' => false,
            'hint' => 'Πουλάς αγαθά που αγοράζεις έτοιμα για μεταπώληση. Τα αγαθά ταξινομούνται ως '
                .'«Εμπορεύματα» (category1_1). Οι υπηρεσίες (αν υπάρχουν) ταξινομούνται από τον τύπο ως υπηρεσίες.',
        ],
        self::MANUFACTURER => [
            'label' => 'Παραγωγός / Βιοτεχνία (πώληση ΔΙΚΩΝ σου προϊόντων)',
            'goods_category' => 'category1_2', // Προϊόντα (own production)
            'requires_per_category' => false,
            'hint' => 'Παράγεις και πουλάς δικά σου προϊόντα. Τα αγαθά ταξινομούνται ως «Προϊόντα» '
                .'(category1_2), ΟΧΙ ως εμπορεύματα — αυτή είναι η διαφορά που κλείνει το MYD-006.',
        ],
        self::SERVICES => [
            'label' => 'Πάροχος υπηρεσιών (π.χ. hosting, συμβουλευτική)',
            'goods_category' => null, // services types already default to category1_3
            'requires_per_category' => false,
            'hint' => 'Πουλάς υπηρεσίες. Ταξινομούνται ως «Παροχή Υπηρεσιών» (category1_3) — ήδη σωστά '
                .'από τον τύπο παραστατικού (ΤΠΥ/ΑΠΥ), χωρίς επιπλέον ρύθμιση για αγαθά.',
        ],
        self::MIXED => [
            'label' => 'Μικτή δραστηριότητα (αγαθά ΚΑΙ υπηρεσίες, ή εμπορεύματα ΚΑΙ δικά σου προϊόντα)',
            'goods_category' => null, // configured per product-category
            'requires_per_category' => true,
            'hint' => 'Συνδυασμός δραστηριοτήτων. Όρισε την κατηγορία εσόδων ΑΝΑ κατηγορία προϊόντος '
                .'(Setup → Κατηγορίες προϊόντων): εμπορεύματα→category1_1, δικά σου προϊόντα→category1_2, '
                .'υπηρεσίες→category1_3. Χωρίς αυτό, τα αγαθά ταξινομούνται προεπιλεγμένα ως εμπορεύματα.',
        ],
    ];

    /**
     * Options for the «Είδος δραστηριότητας» picker: key => label.
     *
     * @return array<string, string>
     */
    public static function options(): array
    {
        $out = [];
        foreach (self::POLICIES as $key => $p) {
            $out[$key] = $p['label'];
        }

        return $out;
    }

    public static function isValid(?string $type): bool
    {
        return $type !== null && array_key_exists($type, self::POLICIES);
    }

    /**
     * The §8.6 bucket a GOODS line files under for this business type, or null
     * when there is no tenant-wide goods default (services / mixed / unset). The
     * caller only substitutes when a line would otherwise take the merchandise
     * default, so a null here is a no-op.
     */
    public static function goodsCategoryFor(?string $type): ?string
    {
        if (! self::isValid($type)) {
            return null;
        }

        return self::POLICIES[$type]['goods_category'];
    }

    /** True for a `mixed` tenant that must classify goods per product-category. */
    public static function requiresPerCategoryConfig(?string $type): bool
    {
        return self::isValid($type) && self::POLICIES[$type]['requires_per_category'];
    }

    public static function labelFor(?string $type): ?string
    {
        return self::isValid($type) ? self::POLICIES[$type]['label'] : null;
    }

    public static function hintFor(?string $type): ?string
    {
        return self::isValid($type) ? self::POLICIES[$type]['hint'] : null;
    }

    public const INTRO =
        'Το είδος δραστηριότητας ορίζει την κατηγορία εσόδων (§8.6) των ΑΓΑΘΩΝ που τιμολογείς: τα '
        .'εμπορεύματα (μεταπώληση) ταξινομούνται ως category1_1, ενώ τα δικά σου προϊόντα (παραγωγή) '
        .'ως category1_2. Δεν υπάρχει ένα σωστό default για όλες τις επιχειρήσεις — γι’ αυτό επιλέγεται '
        .'ρητά. Οι υπηρεσίες ταξινομούνται πάντα ως category1_3 από τον τύπο παραστατικού.';
}
