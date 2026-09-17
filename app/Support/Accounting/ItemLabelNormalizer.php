<?php

namespace App\Support\Accounting;

/**
 * Collapses a free-text invoice-line description into a STABLE item label, so the
 * «Ισοζύγιο Ειδών/Υπηρεσιών» (#4) can group the recurring sale of the same
 * product/service into one row instead of one row per renewal.
 *
 * The problem it solves: WHMCS-bridged (and hand-typed) lines carry NO product_id
 * and embed the billing PERIOD in the description, e.g.
 *   «Dedicate Server Cloud Hosting 16C/64GB/1TB/1G) (1/9/2026-31/8/2027)»
 * so every yearly renewal is a distinct string → grouping by the raw text would
 * emit ~one row per invoice line. Stripping the trailing date-range parenthetical
 * folds all renewals of the same package together.
 *
 * DISPLAY-ONLY: this never touches the stored invoice line — the παραστατικό keeps
 * its exact legal description. It only shapes how the read-only report buckets and
 * labels rows.
 *
 * Conservative on purpose: it removes a trailing «(…)» ONLY when that parenthetical
 * contains a date (a d/m/y or y-m-d token). A parenthetical WITHOUT a date — e.g.
 * «(Annual Plan)», «(Server Setup Fee)» — is part of the product's name and is
 * kept. Interior specs like «16C/64GB/1TB/1G» carry no full date and are untouched.
 */
class ItemLabelNormalizer
{
    /**
     * A trailing parenthetical that holds a billing-period date RANGE — TWO real
     * dates (d/m/yyyy or yyyy/m/d, each with a FOUR-DIGIT 19xx/20xx year), with
     * anything around/between them: «(1/9/2026-31/8/2027)» and «(01/09/2026 -
     * 31/08/2027)» match. Everything else is kept, on purpose:
     *   - a non-date suffix that's part of the name — «(Annual Plan)»;
     *   - a version/IP triple — «(v1.2.3)», «(192.168.1.5)» (no 19xx/20xx year);
     *   - a SINGLE date — «(15/03/2026)» (a dated one-off is a distinct item, not a
     *     renewal of a period, so it must NOT fold with a differently-dated sibling).
     * Requiring two 19xx/20xx-year dates is what pins this to a period range.
     * Separators: / . or - ; `#` delimiter keeps them readable.
     */
    private const TRAILING_DATE_PAREN = '#\s*\((?:[^()]*(?:\d{1,2}[/.\-]\d{1,2}[/.\-](?:19|20)\d{2}|(?:19|20)\d{2}[/.\-]\d{1,2}[/.\-]\d{1,2})){2}[^()]*\)\s*$#u';

    /**
     * The clean display label: the description with any trailing date-range
     * parenthetical(s) removed and internal whitespace collapsed. Original casing
     * is preserved (it's what the operator sees). An empty/whitespace-only input
     * returns ''.
     */
    public static function clean(string $descr): string
    {
        $s = trim($descr);

        // A line can carry more than one trailing date-parenthetical; strip up to
        // two (defensive — one is the norm), stopping as soon as nothing changes.
        for ($i = 0; $i < 2; $i++) {
            $stripped = preg_replace(self::TRAILING_DATE_PAREN, '', $s);
            if ($stripped === null || $stripped === $s) {
                break;
            }
            $s = rtrim($stripped);
        }

        // Collapse runs of whitespace (incl. the gaps a stripped suffix can leave).
        $s = preg_replace('/\s+/u', ' ', $s) ?? $s;

        return trim($s);
    }

    /**
     * The case-insensitive grouping key for a cleaned description — so «HOSTING»
     * and «hosting» fold together. Returns '' when there's nothing to group on.
     */
    public static function key(string $descr): string
    {
        return mb_strtolower(self::clean($descr));
    }
}
