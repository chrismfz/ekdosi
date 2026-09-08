<?php

namespace App\Support;

use Firebed\AadeMyData\Enums\IncomeClassificationCategory;
use Firebed\AadeMyData\Enums\IncomeClassificationType;
use Firebed\AadeMyData\Enums\InvoiceType;

/**
 * Thin shim between firebed's AADE enums and Filament Select fields.
 *
 * firebed/aade-mydata is the source of truth for AADE catalogue codes
 * + their Greek descriptions. The library's `HasLabels::labels()`
 * returns an array of single-key arrays (`[[code => label], ...]`),
 * which doesn't map directly to Filament's expected
 * `[code => display, ...]` Select options shape — so we flatten and
 * prefix each label with the code itself ("1.1 — Τιμολόγιο Πώλησης")
 * since operators reference these codes by number in conversation.
 *
 * Whenever AADE adds / retires a type, the firebed library update
 * propagates through these helpers automatically. Do NOT hardcode
 * codes here — always read from the enum.
 */
class MyDataOptions
{
    /**
     * "1.1 — Τιμολόγιο Πώλησης", "2.1 — Τιμολόγιο Παροχής", … (54 entries).
     */
    public static function invoiceTypes(): array
    {
        return self::format(InvoiceType::cases());
    }

    /**
     * E3_561_001-style income classification types (revenue line types).
     */
    public static function incomeClassificationTypes(): array
    {
        return self::format(IncomeClassificationType::cases());
    }

    /**
     * categoryX_Y-style income classification categories (per-rate buckets).
     */
    public static function incomeClassificationCategories(): array
    {
        return self::format(IncomeClassificationCategory::cases());
    }

    /** @param  array<int, \BackedEnum>  $cases */
    private static function format(array $cases): array
    {
        $out = [];
        foreach ($cases as $case) {
            $out[$case->value] = $case->value.' — '.$case->label();
        }
        return $out;
    }
}
