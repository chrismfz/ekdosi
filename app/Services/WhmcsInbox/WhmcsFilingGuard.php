<?php

namespace App\Services\WhmcsInbox;

use App\Models\PendingWhmcsInvoice;
use LogicException;

/**
 * WH-1 / WH-2 / WH-4 / WH-5 (AUDIT): the filer-level choke-point that stops a
 * WHMCS row from being turned into an ekdosi παραστατικό when the mapping
 * cannot be trusted. Runs on the mapper's output BEFORE any ΑΑ is allocated,
 * so a doomed row is HELD in the inbox instead of leaving a ghost invoice
 * (ΑΑ burned, submit rejected) — the same reasoning as
 * WhmcsInvoiceFiler::refuseProblematicZeroVatLines.
 *
 * Two families, because they apply to different scopes:
 *
 *   - assertPayloadFilable(): currency (WH-1) + negative lines (WH-4) are
 *     facts of the source document — they hold for the whole invoice AND for
 *     any per-party split group, so every filing path calls this (file /
 *     createDraft / split).
 *
 *   - assertTotalsReconcile(): the recomputed gross must match the WHMCS
 *     invoice total (WH-2 wrong VAT rate, WH-5 accumulated rounding). This is
 *     a WHOLE-invoice check — a split group is deliberately a SUBSET of the
 *     total, so only the whole-invoice paths (file / createDraft) call it, and
 *     the split path does not.
 *
 * Throws LogicException with an operator-facing Greek message; the Filament
 * inbox action and whmcs:auto-issue both catch Throwable and surface it (the
 * row stays for a human).
 */
final class WhmcsFilingGuard
{
    /** Reconciliation slack: a base cent + one cent per line for the documented
     *  ±0.01/line back-computation rounding. A wrong VAT rate (e.g. 13% filed as
     *  24%) blows past this by euros, so it's caught; pure rounding passes. */
    private const TOTALS_BASE_TOLERANCE = 0.02;

    private const TOTALS_PER_LINE_TOLERANCE = 0.01;

    /**
     * WH-1 + WH-4: refuse non-EUR invoices and negative (promo/credit) lines.
     * Path-independent — safe on a whole invoice or a single split group.
     *
     * @param  array<string, mixed>  $mapped  WhmcsInvoiceMapper::map() output
     */
    public static function assertPayloadFilable(array $mapped, PendingWhmcsInvoice $pending): void
    {
        $currency = (string) ($mapped['source']['whmcs_currency'] ?? '');
        if ($currency !== '' && $currency !== 'EUR') {
            throw new LogicException(
                'Το WHMCS #'.$pending->whmcs_invoice_id.' είναι σε νόμισμα '.$currency.' — το ekdosi '
                .'εκδίδει μόνο σε EUR (η ΑΑΔΕ δηλώνεται σε EUR). Η γραμμή κρατείται· εξέδωσέ το '
                .'χειροκίνητα με τη σωστή ισοτιμία ή απόρριψέ το.'
            );
        }

        $negative = $mapped['totals']['negative_lines'] ?? [];
        if ($negative !== []) {
            $sample = implode('», «', array_slice($negative, 0, 3));
            $more = count($negative) > 3 ? ' (+'.(count($negative) - 3).' ακόμα)' : '';
            throw new LogicException(
                'Το WHMCS #'.$pending->whmcs_invoice_id.' έχει '.count($negative).' γραμμή/ές με '
                .'αρνητικό ποσό («'.$sample.'»'.$more.') — εκπτωτικά/πιστωτικά WHMCS. Η ΑΑΔΕ '
                .'απορρίπτει αρνητική αξία γραμμής· η γραμμή κρατείται. Τακτοποίησε την έκπτωση '
                .'στο WHMCS (ή εξέδωσε πιστωτικό ξεχωριστά) και ξαναπροσπάθησε.'
            );
        }
    }

    /**
     * WH-2 + WH-5: the recomputed gross must reconcile with the WHMCS invoice
     * total. A large gap means the applied VAT rate differs from what WHMCS
     * charged (our mapper uses the tenant's DEFAULT rate, not the payload's
     * taxrate) — filing it would put a MARK on a different amount than the
     * customer's WHMCS invoice. WHOLE-invoice only.
     *
     * Skipped when the WHMCS total is absent/zero (nothing to compare against).
     *
     * @param  array<string, mixed>  $mapped
     */
    public static function assertTotalsReconcile(array $mapped, PendingWhmcsInvoice $pending): void
    {
        $whmcsTotal = (float) ($mapped['source']['whmcs_total'] ?? 0.0);
        if ($whmcsTotal <= 0.005) {
            return;   // no usable WHMCS total to reconcile against
        }

        $gross = (float) ($mapped['totals']['gross_total'] ?? 0.0);
        $lineCount = count($mapped['lines'] ?? []);
        $tolerance = self::TOTALS_BASE_TOLERANCE + self::TOTALS_PER_LINE_TOLERANCE * $lineCount;

        if (abs($gross - $whmcsTotal) > $tolerance) {
            throw new LogicException(sprintf(
                'Το WHMCS #%d δεν συμφωνεί στα σύνολα: το ekdosi υπολόγισε μικτό %s € αλλά το WHMCS '
                .'σύνολο είναι %s € (διαφορά %s €). Πιθανή αιτία: ο συντελεστής ΦΠΑ του WHMCS '
                .'διαφέρει από τον προεπιλεγμένο ΦΠΑ του πελάτη/τύπου. Η γραμμή κρατείται — '
                .'έλεγξε τον συντελεστή ΦΠΑ και εξέδωσέ το χειροκίνητα.',
                $pending->whmcs_invoice_id,
                number_format($gross, 2, ',', '.'),
                number_format($whmcsTotal, 2, ',', '.'),
                number_format(abs($gross - $whmcsTotal), 2, ',', '.'),
            ));
        }
    }

    /**
     * WH-2: the VAT rate WHMCS charged must match the rate the mapper applied
     * (the tenant's default). The gross-reconcile above misses this in WHMCS
     * tax-INCLUSIVE mode — there the mapper reproduces each gross amount, so
     * the total matches even though the net/VAT SPLIT filed to AADE is wrong.
     * Comparing the rates catches it in both modes. WHOLE-invoice only.
     *
     * Skipped when WHMCS reports no tax rate (taxrate 0 — a fully-untaxed /
     * exempt invoice, handled by the 0%-VAT path) or when the mapping produced
     * no taxed lines to compare against.
     *
     * @param  array<string, mixed>  $mapped
     */
    public static function assertVatRateReconciles(array $mapped, PendingWhmcsInvoice $pending): void
    {
        $whmcsRate = (float) ($mapped['source']['whmcs_taxrate'] ?? 0.0);
        if ($whmcsRate <= 0.005) {
            return;   // WHMCS charged no VAT — nothing to reconcile
        }

        // The non-zero rate(s) the mapper actually applied to taxed lines.
        $appliedRates = [];
        foreach ($mapped['totals']['vat_breakdown'] ?? [] as $row) {
            $rate = (float) ($row['rate'] ?? 0.0);
            if ($rate > 0.005) {
                $appliedRates[] = $rate;
            }
        }
        if ($appliedRates === []) {
            return;   // no taxed lines mapped — nothing to reconcile
        }

        foreach ($appliedRates as $applied) {
            if (abs($applied - $whmcsRate) > 0.01) {
                throw new LogicException(sprintf(
                    'Το WHMCS #%d χρεώθηκε με ΦΠΑ %s%% αλλά το ekdosi εφαρμόζει τον προεπιλεγμένο '
                    .'%s%% — το φιλτραρισμένο ΦΠΑ θα δηλωνόταν λάθος στην ΑΑΔΕ. Ο mapper δεν '
                    .'αντιστοιχίζει ανά συντελεστή· η γραμμή κρατείται — εξέδωσέ το χειροκίνητα με '
                    .'τον σωστό συντελεστή ΦΠΑ.',
                    $pending->whmcs_invoice_id,
                    rtrim(rtrim(number_format($whmcsRate, 2, '.', ''), '0'), '.'),
                    rtrim(rtrim(number_format($applied, 2, '.', ''), '0'), '.'),
                ));
            }
        }
    }
}
