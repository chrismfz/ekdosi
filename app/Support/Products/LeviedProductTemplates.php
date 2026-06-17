<?php

namespace App\Support\Products;

/**
 * Curated catalogue of «θεσμικά δεμένα τέλη» — Greek statutory per-unit levies
 * that ride on a product/service, pre-mapped to the correct myDATA fee so an
 * operator can IMPORT them ready-made instead of looking up §8.x codes + amounts.
 *
 * All are myDATA **Fees (§8.5)** (`mydata_tax_type = 2`) with a fixed €/unit
 * amount (`mydata_tax_per_unit`) and the matching FeesPercentCategory value
 * (`mydata_tax_category`). `fixed=false` flags a levy whose amount varies (the
 * operator must adjust it after import).
 *
 * The product-linked-fee MECHANICS already exist (RecomputeInvoiceTaxes auto-sums
 * qty × per_unit at issue + files it). This is only the ready data + a selective
 * importer (ImportLeviedProducts). Single source — refresh on a law change.
 */
class LeviedProductTemplates
{
    /** myDATA fee taxType for every levy here: Fees §8.5. */
    public const TAX_TYPE_FEES = 2;

    /**
     * @return array<string, array{key:string,name:string,tax_category:int,per_unit:float,unit:string,fixed:bool,note:string}>
     */
    public static function all(): array
    {
        return [
            'plastic_bag' => [
                'key' => 'plastic_bag',
                'name' => 'Πλαστική σακούλα — περιβαλλοντικό τέλος',
                'tax_category' => 8,   // FeesPercentCategory::TYPE_8
                'per_unit' => 0.07,
                'unit' => 'τεμ',
                'fixed' => true,
                'note' => 'ν. 2339/2001 αρ. 6α — €0,07 ανά τεμάχιο (σταθερό).',
            ],
            'plastic_levy' => [
                'key' => 'plastic_levy',
                'name' => 'Εισφορά προστασίας περιβάλλοντος πλαστικών προϊόντων',
                'tax_category' => 16,  // FeesPercentCategory::TYPE_16
                'per_unit' => 0.04,
                'unit' => 'τεμ',
                'fixed' => true,
                'note' => 'άρθρο 4 ν. 4736/2020 — €0,04 ανά τεμάχιο.',
            ],
            'recycling' => [
                'key' => 'recycling',
                'name' => 'Τέλος ανακύκλωσης',
                'tax_category' => 17,  // FeesPercentCategory::TYPE_17
                'per_unit' => 0.08,
                'unit' => 'τεμ',
                'fixed' => true,
                'note' => 'άρθρο 80 ν. 4819/2021 — €0,08 ανά τεμάχιο.',
            ],
            'accommodation' => [
                'key' => 'accommodation',
                'name' => 'Τέλος διαμονής παρεπιδημούντων',
                'tax_category' => 18,  // FeesPercentCategory::TYPE_18
                'per_unit' => 0.50,
                'unit' => 'διαν.',
                'fixed' => false,
                'note' => 'Ποικίλλει ανά κατηγορία/εποχή — ΡΥΘΜΙΣΤΕ το ποσό ανά μονάδα μετά την εισαγωγή.',
            ],
        ];
    }
}
