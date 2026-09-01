<?php

namespace App\Services\MyData;

use Carbon\CarbonImmutable;
use Throwable;

/**
 * Content-level cross-check for a live reconciliation (MYD-017). Given a local
 * document and the AADE summary for the SAME MARK — both already known to agree
 * on cancellation state — it reports which legally-relevant fields DIFFER.
 *
 * A MARK/state pair that agrees is NOT proof the content matches: a post-filing
 * local edit, an incomplete import or a wrong MARK association can leave the
 * local gross/type/series/AA/date/counterpart different from what AADE holds,
 * yet the old reconciler counted it as "matched" — a false green.
 *
 * This is a PURE read shared by BOTH SalesReconciler and ExpenseReconciler (so
 * the rule can't drift). It returns operator-facing Greek descriptions of the
 * differing fields; an empty list means the content matches. It NEVER mutates a
 * frozen value — the caller only uses the result to route a row to `matched` vs
 * the new `contentMismatch` bucket.
 *
 * Comparison rules (each side compared only when meaningfully present):
 *   - gross: both non-null and beyond an explicit cent tolerance.
 *   - invoice type (§8.1): both present and unequal.
 *   - series / ΑΑ: only when BOTH sides carry the field (string, trimmed) — a
 *     null/blank LOCAL value is an incomplete record, not a content conflict.
 *   - issue date: both parsed to Y-m-d (raw compare if unparseable).
 *   - counterpart AFM: only when BOTH sides carry one (retail 11.x has none at
 *     AADE), compared digits-only so an EL/GR prefix is not a false difference.
 */
final class ReconciliationContentComparator
{
    /** Gross values within this many currency units are treated as equal. */
    private const GROSS_TOLERANCE = 0.01;

    /**
     * @return list<string> Greek field-diff descriptions; empty = content matches.
     */
    public static function diffs(LocalDocSnapshot $local, AadeDocSummary $aade): array
    {
        $out = [];

        if ($local->gross !== null && $aade->gross !== null
            && abs($local->gross - $aade->gross) > self::GROSS_TOLERANCE) {
            $out[] = 'μικτό: '.self::money($local->gross).' τοπικά / '.self::money($aade->gross).' ΑΑΔΕ';
        }

        if ($aade->invoiceType !== null && $local->invoiceType !== null
            && $aade->invoiceType !== $local->invoiceType) {
            $out[] = 'τύπος: '.$local->invoiceType.' τοπικά / '.$aade->invoiceType.' ΑΑΔΕ';
        }

        // series / ΑΑ: only when BOTH sides carry the field. A null/blank LOCAL
        // value (e.g. a legacy-imported or hand-keyed doc that never captured the
        // series) is an INCOMPLETE record, not a content CONFLICT — flagging it
        // would flip every such correct-but-sparse doc into the danger bucket and
        // balloon the discrepancy count (MYD-017 review).
        if (self::present($aade->series) && self::present($local->series)
            && trim((string) $aade->series) !== trim((string) $local->series)) {
            $out[] = 'σειρά: '.$local->series.' τοπικά / '.$aade->series.' ΑΑΔΕ';
        }

        if (self::present($aade->aa) && self::present($local->aa)
            && trim((string) $aade->aa) !== trim((string) $local->aa)) {
            $out[] = 'ΑΑ: '.$local->aa.' τοπικά / '.$aade->aa.' ΑΑΔΕ';
        }

        if ($aade->issueDate !== null && $local->issueDate !== null
            && self::normDate($aade->issueDate) !== self::normDate($local->issueDate)) {
            $out[] = 'ημ/νία: '.$local->issueDate.' τοπικά / '.$aade->issueDate.' ΑΑΔΕ';
        }

        // Counterpart ΑΦΜ: only when BOTH carry one (retail 11.x has none at AADE;
        // a null local ΑΦΜ is incompleteness, not a conflict), compared digits-only
        // so an EL/GR prefix is not a false difference.
        if (self::present($aade->counterpartVat) && self::present($local->counterpartVat)
            && self::digits($aade->counterpartVat) !== self::digits($local->counterpartVat)) {
            $out[] = 'ΑΦΜ: '.$local->counterpartVat.' τοπικά / '.$aade->counterpartVat.' ΑΑΔΕ';
        }

        return $out;
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
