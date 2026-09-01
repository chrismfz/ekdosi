<?php

namespace App\Services\MyData;

use App\Support\Afm;
use App\Support\Money;
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
 * money (gross + net) is compared in whole cents with a one-cent tolerance (App\\Support\\Money);
 * ΑΦΜ is compared digits-only (App\\Support\\Afm) so an EL/GR prefix is not a false difference;
 * dates are normalised to Y-m-d.
 */
final class ReconciliationContentComparator
{
    /** Suffix for a field AADE did not give us (so we could not verify it). */
    private const UNVERIFIED = ' — ανεπαλήθευτο)';

    public static function compare(LocalDocSnapshot $local, AadeDocSummary $aade): ContentComparison
    {
        $conflicts = [];
        $incompletes = [];

        // gross + net (both mandatory). Net catches a wrong VAT category whose net
        // and vat compensate to the SAME gross (100+24 locally vs 110+14 at AADE) —
        // the split is exactly what myDATA reports on.
        self::compareMoney('μικτό', $local->gross, $aade->gross, $conflicts, $incompletes);
        self::compareMoney('καθαρή αξία', $local->net, $aade->net, $conflicts, $incompletes);

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
            } elseif (Afm::digits($aade->counterpartVat) !== Afm::digits($local->counterpartVat)) {
                $conflicts[] = 'ΑΦΜ: '.$local->counterpartVat.' τοπικά / '.$aade->counterpartVat.' ΑΑΔΕ';
            }
        }

        return new ContentComparison($conflicts, $incompletes);
    }

    /**
     * One mandatory money field, three outcomes: AADE-missing → unverified,
     * local-missing → unverified, both present but differ (by >1 cent) → conflict.
     */
    private static function compareMoney(string $label, ?float $local, ?float $aade, array &$conflicts, array &$incompletes): void
    {
        if ($aade === null) {
            $incompletes[] = $label.' (λείπει από την ΑΑΔΕ'.self::UNVERIFIED;
        } elseif ($local === null) {
            $incompletes[] = $label.' (δεν προσδιορίζεται τοπικά· ΑΑΔΕ '.self::money($aade).')';
        } elseif (Money::differsByCent($local, $aade)) {
            $conflicts[] = $label.': '.self::money($local).' τοπικά / '.self::money($aade).' ΑΑΔΕ';
        }
    }

    private static function money(float $v): string
    {
        return number_format($v, 2, ',', '.');
    }

    private static function present(?string $v): bool
    {
        return $v !== null && trim($v) !== '';
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
