<?php

namespace App\Support\MyData;

/**
 * The one definition of «is this a cancellation MARK we are willing to record?»
 * (MYD-023).
 *
 * A cancellation produces its own MARK, distinct from the MARK of the document
 * being cancelled, and it is persisted as legal evidence. Three services write
 * that row — the direct invoice cancel, the direct/provider delivery cancel and
 * the provider invoice cancel — and each was normalising (or not normalising)
 * the value itself. Two review rounds found the SAME defect at three of those
 * sites, which is the signal to collapse the policy rather than patch the next
 * call site.
 *
 * The rule: an absent MARK is NULL, never `''`. firebed's
 * `Response::getCancellationMark()` returns `''` — not null — for
 * `<cancellationMark></cancellationMark>`, and the audit rows are built with
 * `array_filter(…, fn ($v) => $v !== null)`, which strips only nulls. So an
 * empty MARK would persist and be counted as evidence by every `IS NOT NULL`
 * query — «we hold a cancellation MARK» when we hold nothing.
 */
final class CancellationMark
{
    /**
     * A MARK from AADE or the provider: trimmed, with «absent» collapsed to null.
     *
     * Deliberately NOT shape-checked. These values come from the transport we
     * just authenticated against, and refusing an unexpected shape here would
     * discard real evidence over a format assumption.
     */
    public static function clean(?string $mark): ?string
    {
        $mark = trim((string) $mark);

        return $mark !== '' ? $mark : null;
    }

    /**
     * A MARK that reached us through something a client can write — today a
     * public Livewire property on the MARK detail page.
     *
     * Same normalisation, plus a shape check, because the threat is different:
     * an arbitrary string here would be recorded as «AADE's cancellation MARK»
     * in a legal audit trail (fabricated evidence), and one longer than the
     * column would abort the write outright. A MARK is a numeric AADE
     * identifier; anything else is recorded as «no evidence» rather than as
     * evidence of something we cannot vouch for. 40 = the column width, checked
     * so neither a crafted value nor a future-longer MARK truncates.
     */
    public static function fromUntrusted(?string $mark): ?string
    {
        $mark = self::clean($mark);

        return $mark !== null && preg_match('/^\d{1,40}$/', $mark) === 1 ? $mark : null;
    }
}
