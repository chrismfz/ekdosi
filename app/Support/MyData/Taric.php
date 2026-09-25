<?php

namespace App\Support\MyData;

/**
 * Item code for the «Ενιαία Κωδικοποίηση Ειδών» (Α.1094/2026 → Α.1123/2024 άρθ.6: Συνδυασμένη
 * Ονοματολογία, mandatory from 1/1/2027) as myDATA's `TaricNo` wants it: EXACTLY 10 characters
 * (an 8-digit CN code is rejected by the XML schema, [101], AADE sandbox 2026-09-25) → an 8-digit
 * code is extended with «00» (the TARIC sub-heading level; AADE has published no other rule).
 * Accepted only on a combined ΤΔΑ or a 9.x δελτίο (§5.4) — {@see self::appliesTo()}.
 */
final class Taric
{
    /** Digits only; 8 → +«00»; 10 → as is; empty → null; anything else → null (invalid). */
    public static function normalize(?string $code): ?string
    {
        $digits = preg_replace('/\D+/', '', (string) $code) ?? '';

        return match (strlen($digits)) {
            8 => $digits.'00',
            10 => $digits,
            default => null,
        };
    }

    /** Valid input for a form: blank, or 8 / 10 digits (spaces/dots allowed, e.g. «8471 30 00»). */
    public static function isValidInput(?string $code): bool
    {
        return trim((string) $code) === '' || self::normalize($code) !== null;
    }

    /**
     * Does myDATA accept TaricNo / itemCode on this document? §5.4 uses the SAME wording as
     * for itemDescr — «τιμολόγια ΚΑΙ δελτία αποστολής ή απλά δελτία διακίνησης (π.χ 9.3)»,
     * i.e. a combined ΤΔΑ or a 9.x note, NOT a plain sales invoice (AADE rejects itemDescr
     * there, sandbox-proven) — so it reuses that rule rather than guessing a wider one.
     */
    public static function appliesTo(?string $mydataType, bool $isDeliveryNote = false): bool
    {
        return Codes::allowsItemDescr($mydataType, $isDeliveryNote);
    }

    /**
     * [TaricNo, itemCode] for a document line at SUBMISSION: the line's snapshot, else — a
     * draft created before its product got a code (e.g. codes filled in December, the draft
     * filed in January) — the product's current one. Only the builders call this, i.e. at
     * filing time, which is when the code becomes part of the legal document.
     *
     * @return array{0: ?string, 1: ?string}
     */
    public static function lineCodes(object $line): array
    {
        $product = ($line->taric_code && $line->item_code) ? null : $line->product;
        $taric = $line->taric_code ?: $product?->taric_code;
        $item = $line->item_code ?: (filled($product?->sku) ? mb_substr((string) $product->sku, 0, 50) : null);

        return [$taric ?: null, $item ?: null];
    }
}
