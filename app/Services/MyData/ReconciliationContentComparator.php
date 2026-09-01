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
 * Per field, comparing ONLY what AADE actually reported (AADE is the source of
 * truth for the window):
 *   - AADE present + local present + values differ → a CONFLICT.
 *   - AADE present + local absent                  → INCOMPLETE (unverified; the
 *     local record never captured it — not a conflict, but not reconciled either).
 *   - AADE absent (e.g. counterpart ΑΦΜ on retail 11.x) → skipped (nothing to
 *     verify against).
 *
 * gross uses an explicit cent tolerance; ΑΦΜ is compared digits-only so an EL/GR
 * prefix is not a false difference; dates are normalised to Y-m-d.
 */
final class ReconciliationContentComparator
{
    /** Gross values within this many currency units are treated as equal. */
    private const GROSS_TOLERANCE = 0.01;

    public static function compare(LocalDocSnapshot $local, AadeDocSummary $aade): ContentComparison
    {
        $conflicts = [];
        $incompletes = [];

        // gross
        if ($aade->gross !== null) {
            if ($local->gross === null) {
                $incompletes[] = 'μικτό (λείπει τοπικά· ΑΑΔΕ '.self::money($aade->gross).')';
            } elseif (abs($local->gross - $aade->gross) > self::GROSS_TOLERANCE) {
                $conflicts[] = 'μικτό: '.self::money($local->gross).' τοπικά / '.self::money($aade->gross).' ΑΑΔΕ';
            }
        }

        // invoice type (§8.1)
        if ($aade->invoiceType !== null) {
            if (! self::present($local->invoiceType)) {
                $incompletes[] = 'τύπος (λείπει τοπικά· ΑΑΔΕ '.$aade->invoiceType.')';
            } elseif ($aade->invoiceType !== $local->invoiceType) {
                $conflicts[] = 'τύπος: '.$local->invoiceType.' τοπικά / '.$aade->invoiceType.' ΑΑΔΕ';
            }
        }

        // series
        if (self::present($aade->series)) {
            if (! self::present($local->series)) {
                $incompletes[] = 'σειρά (λείπει τοπικά· ΑΑΔΕ '.$aade->series.')';
            } elseif (trim((string) $aade->series) !== trim((string) $local->series)) {
                $conflicts[] = 'σειρά: '.$local->series.' τοπικά / '.$aade->series.' ΑΑΔΕ';
            }
        }

        // ΑΑ
        if (self::present($aade->aa)) {
            if (! self::present($local->aa)) {
                $incompletes[] = 'ΑΑ (λείπει τοπικά· ΑΑΔΕ '.$aade->aa.')';
            } elseif (trim((string) $aade->aa) !== trim((string) $local->aa)) {
                $conflicts[] = 'ΑΑ: '.$local->aa.' τοπικά / '.$aade->aa.' ΑΑΔΕ';
            }
        }

        // issue date
        if ($aade->issueDate !== null) {
            if ($local->issueDate === null) {
                $incompletes[] = 'ημ/νία (λείπει τοπικά· ΑΑΔΕ '.$aade->issueDate.')';
            } elseif (self::normDate($aade->issueDate) !== self::normDate($local->issueDate)) {
                $conflicts[] = 'ημ/νία: '.$local->issueDate.' τοπικά / '.$aade->issueDate.' ΑΑΔΕ';
            }
        }

        // counterpart ΑΦΜ — only when AADE returns one (retail 11.x has none → skip)
        if (self::present($aade->counterpartVat)) {
            if (! self::present($local->counterpartVat)) {
                $incompletes[] = 'ΑΦΜ (λείπει τοπικά· ΑΑΔΕ '.$aade->counterpartVat.')';
            } elseif (self::digits($aade->counterpartVat) !== self::digits($local->counterpartVat)) {
                $conflicts[] = 'ΑΦΜ: '.$local->counterpartVat.' τοπικά / '.$aade->counterpartVat.' ΑΑΔΕ';
            }
        }

        return new ContentComparison($conflicts, $incompletes);
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

    /** Normalise a date to Y-m-d; fall back to the trimmed raw value if unparseable. */
    private static function normDate(string $v): string
    {
        try {
            return CarbonImmutable::parse($v)->format('Y-m-d');
        } catch (Throwable) {
            return trim($v);
        }
    }
}
