<?php

namespace App\Support;

use App\Services\EInvoice\AadeInvoiceDocument;

/**
 * The PROVISIONAL identity a numbered document carries while it is a draft or
 * finalized-but-unsent — before the real ΑΑ is allocated at transmission
 * (gapless-at-send, reverses MON-4). Shape: «ΠΡΟΣ-{τύπος}-{id}», e.g.
 * «ΠΡΟΣ-ΤΠΥ-6885». It is never sent to the ΑΑΔΕ (the payload carries the real
 * `code`/`series`, and {@see AadeInvoiceDocument} throws
 * on a null `code`); it exists only so every internal surface that reads
 * `invcode` shows a stable, clearly-provisional, per-document-unique label
 * instead of a real invoice number the document has not earned yet.
 */
final class ProvisionalCode
{
    /** The «προσωρινό» marker. Kept distinct from any real series prefix. */
    public const PREFIX = 'ΠΡΟΣ';

    /** «ΠΡΟΣ-ΤΠΥ-6885» — unique per document via its surrogate id. */
    public static function make(?string $typeCode, int|string $id): string
    {
        $type = trim((string) $typeCode);

        return self::PREFIX.'-'.($type !== '' ? $type.'-' : '').$id;
    }

    /** Does this invcode denote a not-yet-issued (provisional) document? */
    public static function is(?string $invcode): bool
    {
        return $invcode !== null && str_starts_with($invcode, self::PREFIX.'-');
    }
}
