<?php

namespace App\Services\MyData;

use Carbon\CarbonImmutable;
use Throwable;

/**
 * Content-level cross-check for a live reconciliation (MYD-017). Given a local
 * document and the AADE summary for the SAME MARK — both already known to agree
 * on cancellation state — it reports how the legally-relevant fields diverge.
 *
 * A MARK/state pair that agrees is NOT proof the content matches: a post-filing
 * local edit, an incomplete import or a wrong MARK association can leave the
 * local gross/type/series/AA/date/counterpart different from — or MISSING against
 * — what AADE holds, yet the old reconciler counted it as "matched" (a false green).
 *
 * This is a PURE read shared by BOTH SalesReconciler and ExpenseReconciler (so
 * the rule can't drift). It NEVER mutates a frozen value — the caller only uses
 * the result to route a row to matched / contentMismatch / contentIncomplete.
 *
 * Three outcomes per field, and "unverifiable" is deliberately NOT "matched":
 *   - both sides present + values differ → a CONFLICT (danger).
 *   - AADE present + local absent        → INCOMPLETE (the local record never
 *     captured it — not a conflict, but not reconciled either).
 *   - AADE absent/unreadable on a MANDATORY field → INCOMPLETE too. We could not
 *     verify the document at all, so it must never read green just because the
 *     source side was empty (that is fail-OPEN). The mandatory header per AADE is
 *     gross, NET, §8.1 type, series, ΑΑ and issue date.
 *   - counterpart ΑΦΜ is the ONE optional field: retail (11.x) legitimately has
 *     no counterpart, so an absent AADE ΑΦΜ is genuinely "nothing to verify".
 *
 * money (gross + net) is compared in whole cents with a one-cent tolerance; ΑΦΜ
 * is compared digits-only so an EL/GR
 * prefix is not a false difference; dates are normalised to Y-m-d.
 */
final class ReconciliationContentComparator
{
    /** Money within this many CENTS is treated as equal (see moneyDiffers). */
    private const MONEY_TOLERANCE_CENTS = 1;

    /** Suffix for a field AADE did not give us (so we could not verify it). */
    private const UNVERIFIED = ' — ανεπαλήθευτο)';

    public static function compare(LocalDocSnapshot $local, AadeDocSummary $aade): ContentComparison
    {
        $conflicts = [];
        $incompletes = [];

        // gross (mandatory)
        if ($aade->gross === null) {
            $incompletes[] = 'μικτό (λείπει από την ΑΑΔΕ'.self::UNVERIFIED;
        } elseif ($local->gross === null) {
            $incompletes[] = 'μικτό (δεν προσδιορίζεται τοπικά· ΑΑΔΕ '.self::money($aade->gross).')';
        } elseif (self::moneyDiffers($local->gross, $aade->gross)) {
            $conflicts[] = 'μικτό: '.self::money($local->gross).' τοπικά / '.self::money($aade->gross).' ΑΑΔΕ';
        }

        // net (mandatory). Gross alone cannot catch a wrong VAT category whose net
        // and vat compensate to the SAME gross (e.g. 100+24 locally vs 110+14 at
        // AADE) — the VAT split is exactly what myDATA reports on, so compare it.
        if ($aade->net === null) {
            $incompletes[] = 'καθαρή αξία (λείπει από την ΑΑΔΕ'.self::UNVERIFIED;
        } elseif ($local->net === null) {
            $incompletes[] = 'καθαρή αξία (δεν προσδιορίζεται τοπικά· ΑΑΔΕ '.self::money($aade->net).')';
        } elseif (self::moneyDiffers($local->net, $aade->net)) {
            $conflicts[] = 'καθαρή αξία: '.self::money($local->net).' τοπικά / '.self::money($aade->net).' ΑΑΔΕ';
        }

        // invoice type §8.1 (mandatory)
        if (! self::present($aade->invoiceType)) {
            $incompletes[] = 'τύπος (λείπει από την ΑΑΔΕ'.self::UNVERIFIED;
        } elseif (! self::present($local->invoiceType)) {
            $incompletes[] = 'τύπος (λείπει τοπικά· ΑΑΔΕ '.$aade->invoiceType.')';
        } elseif (trim((string) $aade->invoiceType) !== trim((string) $local->invoiceType)) {
            $conflicts[] = 'τύπος: '.$local->invoiceType.' τοπικά / '.$aade->invoiceType.' ΑΑΔΕ';
        }

        // series (mandatory)
        if (! self::present($aade->series)) {
            $incompletes[] = 'σειρά (λείπει από την ΑΑΔΕ'.self::UNVERIFIED;
        } elseif (! self::present($local->series)) {
            $incompletes[] = 'σειρά (λείπει τοπικά· ΑΑΔΕ '.$aade->series.')';
        } elseif (trim((string) $aade->series) !== trim((string) $local->series)) {
            $conflicts[] = 'σειρά: '.$local->series.' τοπικά / '.$aade->series.' ΑΑΔΕ';
        }

        // ΑΑ (mandatory)
        if (! self::present($aade->aa)) {
            $incompletes[] = 'ΑΑ (λείπει από την ΑΑΔΕ'.self::UNVERIFIED;
        } elseif (! self::present($local->aa)) {
            $incompletes[] = 'ΑΑ (λείπει τοπικά· ΑΑΔΕ '.$aade->aa.')';
        } elseif (trim((string) $aade->aa) !== trim((string) $local->aa)) {
            $conflicts[] = 'ΑΑ: '.$local->aa.' τοπικά / '.$aade->aa.' ΑΑΔΕ';
        }

        // issue date (mandatory). A blank or unparseable AADE date is UNVERIFIED,
        // never "matched": CarbonImmutable::parse('') would return TODAY, so it must
        // not reach the comparison — but skipping it silently was the fail-open bug.
        $aadeDate = self::present($aade->issueDate) ? self::normDate($aade->issueDate) : null;
        if ($aadeDate === null) {
            $incompletes[] = 'ημ/νία (λείπει ή δεν αναγνωρίζεται από την ΑΑΔΕ'.self::UNVERIFIED;
        } elseif (! self::present($local->issueDate)) {
            $incompletes[] = 'ημ/νία (λείπει τοπικά· ΑΑΔΕ '.$aade->issueDate.')';
        } else {
            $localDate = self::normDate($local->issueDate);
            if ($localDate === null) {
                $incompletes[] = 'ημ/νία (μη αναγνώσιμη τοπικά· ΑΑΔΕ '.$aade->issueDate.')';
            } elseif ($aadeDate !== $localDate) {
                $conflicts[] = 'ημ/νία: '.$local->issueDate.' τοπικά / '.$aade->issueDate.' ΑΑΔΕ';
            }
        }

        // counterpart ΑΦΜ — the ONE optional field: myDATA omits the counterpart for
        // retail (11.x), so an absent AADE ΑΦΜ really is "nothing to verify".
        if (self::present($aade->counterpartVat)) {
            if (! self::present($local->counterpartVat)) {
                $incompletes[] = 'ΑΦΜ (λείπει τοπικά· ΑΑΔΕ '.$aade->counterpartVat.')';
            } elseif (self::digits($aade->counterpartVat) !== self::digits($local->counterpartVat)) {
                $conflicts[] = 'ΑΦΜ: '.$local->counterpartVat.' τοπικά / '.$aade->counterpartVat.' ΑΑΔΕ';
            }
        }

        return new ContentComparison($conflicts, $incompletes);
    }

    /**
     * Compare money in whole cents. A float `abs($a - $b) > 0.01` is
     * magnitude-dependent — 124.00 vs 123.99 lands just above the threshold while
     * 1240.00 vs 1239.99 lands just below — so an exact one-cent difference would
     * be a conflict at some totals and equal at others.
     */
    private static function moneyDiffers(float $local, float $aade): bool
    {
        return abs((int) round($local * 100) - (int) round($aade * 100)) > self::MONEY_TOLERANCE_CENTS;
    }

    private static function money(float $v): string
    {
        return number_format($v, 2, ',', '.');
    }

    private static function present(?string $v): bool
    {
        return $v !== null && trim($v) !== '';
    }

    private static function digits(?string $v): string
    {
        return preg_replace('/\D+/', '', (string) $v) ?? '';
    }

    /** Normalise a date to Y-m-d; null (never a fabricated date) if blank/unparseable. */
    private static function normDate(string $v): ?string
    {
        $v = trim($v);
        if ($v === '') {
            return null;
        }

        try {
            return CarbonImmutable::parse($v)->format('Y-m-d');
        } catch (Throwable) {
            return null;
        }
    }
}
