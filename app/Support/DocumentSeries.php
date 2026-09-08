<?php

namespace App\Support;

/**
 * The FILED series of a numbered document (MYD-018 / MYD-024).
 *
 * A παραστατικό is identified to AADE by (series, ΑΑ). The ΑΑ has always been
 * frozen on the row (`code`), but the series was read live from
 * `invoice_types.code` — an editable lookup. Renaming a series therefore
 * retroactively changed what a document claimed to be, and, worse, made the
 * in-doubt recovery search AADE for the WRONG (series, ΑΑ): it would not find
 * the MARK that already exists and would file the document a SECOND time.
 *
 * The series is now frozen per document. It does not need to be guessed for
 * existing rows either: `invcode` is itself frozen and is exactly
 * `series . code` (legacy GET_INV_CODE concatenates with no padding or
 * separator, and InvoiceNumberer reproduces that), so the historical series can
 * be recovered from it precisely rather than approximated from today's lookup.
 *
 * ONE definition, used by the models' creating hooks and by the migration
 * backfill, so a stored value and a recovered one can never disagree.
 */
final class DocumentSeries
{
    /**
     * Recover the series from a document's frozen `invcode` + `code`.
     *
     * Returns null when the pair does not have the expected shape — a
     * hand-edited invcode, or an ETL row whose legacy code was formatted
     * differently. The caller then falls back to the live type code, which is
     * what the whole codebase did before, so an odd row is never made worse.
     */
    public static function fromInvcode(?string $invcode, int|string|null $code): ?string
    {
        $invcode = trim((string) $invcode);
        $aa = trim((string) $code);

        if ($invcode === '' || $aa === '' || $aa === '0') {
            return null;
        }

        if (! str_ends_with($invcode, $aa)) {
            return null;
        }

        // mb_substr, NOT substr: the series is routinely Greek («ΤΠΥ100»), and
        // mixing a byte-offset cut with a character-length count sliced a
        // multi-byte letter in half.
        $series = mb_substr($invcode, 0, mb_strlen($invcode) - mb_strlen($aa));

        return $series !== '' ? $series : null;
    }

    /**
     * The series a document was ACTUALLY filed under, read out of the request XML
     * we stored when we sent it.
     *
     * This is the authoritative source and it beats `invcode` for one real case:
     * a draft numbered under «ΤΠΥ», the type renamed to «ΤΠΥ2», and only then
     * filed. AADE holds ΤΠΥ2; `invcode` still says ΤΠΥ. Freezing ΤΠΥ there would
     * turn a row the reconciler currently MATCHES into a permanent conflict — a
     * fix creating the problem it exists to prevent. Outside that window the two
     * agree, so this only ever refines the answer.
     *
     * Null for anything it cannot read with certainty: a CANCEL row (whose
     * `request` is a free-text reason, not XML), a dry-run, a row with no request
     * stored. The caller then falls back to `fromInvcode()`.
     */
    public static function fromRequestXml(?string $xml): ?string
    {
        if ($xml === null || ! str_contains($xml, '<')) {
            return null;
        }

        // Optional namespace prefix; the payload carries exactly one invoice, and
        // the header's series is the first occurrence either way.
        if (preg_match('#<(?:[A-Za-z0-9_.-]+:)?series>(.*?)</(?:[A-Za-z0-9_.-]+:)?series>#u', $xml, $m) !== 1) {
            return null;
        }

        $series = trim(html_entity_decode($m[1], ENT_QUOTES | ENT_XML1, 'UTF-8'));

        return $series !== '' ? $series : null;
    }
}
