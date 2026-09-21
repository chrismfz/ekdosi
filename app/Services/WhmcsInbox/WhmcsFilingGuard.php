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

        // WH-8(b): a line with a real (non-zero) amount but a BLANK description
        // is dropped by the mapper (a tax line needs a description) — silently
        // under-billing on the createDraft / split paths, which don't run the
        // totals-reconcile guard. Refuse here (path-independent) so the row is
        // HELD instead of issued for less than the customer actually owes.
        $blankCharges = $mapped['totals']['blank_description_charge_lines'] ?? [];
        if ($blankCharges !== []) {
            $sum = array_sum(array_map('abs', $blankCharges));
            throw new LogicException(
                'Το WHMCS #'.$pending->whmcs_invoice_id.' έχει '.count($blankCharges).' γραμμή/ές με '
                .'ποσό αλλά ΚΕΝΗ περιγραφή (σύνολο '.number_format($sum, 2, ',', '.').' €) — μια '
                .'χρέωση χωρίς περιγραφή δεν επιτρέπεται σε φορολογικό παραστατικό και θα χανόταν '
                .'σιωπηλά (θα εκδιδόταν λιγότερο από το οφειλόμενο). Η γραμμή κρατείται· συμπλήρωσε '
                .'περιγραφή στη γραμμή του WHMCS τιμολογίου και ξαναπροσπάθησε.'
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

    /**
     * UNATTENDED-ONLY: refuse to auto-issue a WHMCS invoice whose mapping carries
     * ANY 0%-VAT line. Such a line means WHMCS sent the product untaxed (Apply Tax
     * off) — which is EITHER a legacy/grandfathered product that legally still
     * owes 24% (the common case: an old package priced gross) OR a genuine
     * exempt / ενδοκοινοτικό reverse-charge sale. The unattended path CANNOT tell
     * the two apart from the payload, so it HOLDS the row for a human — who either
     * sets 24% (gross-edit keeps the customer total) or confirms the exemption in
     * the editable draft. Deliberately NOT called on the manual createDraft path:
     * draft-first exists precisely so an operator can fix exactly this. WHOLE-
     * invoice; runs BEFORE the exemption-aware refuseProblematicZeroVatLines so a
     * tenant with a single configured 0%-exemption still can't auto-file 0% here.
     *
     * @param  array<string, mixed>  $mapped  WhmcsInvoiceMapper::map() output
     */
    public static function assertNoUntaxedForUnattendedIssue(array $mapped, PendingWhmcsInvoice $pending): void
    {
        $zero = $mapped['totals']['zero_vat_lines'] ?? [];
        if ($zero === []) {
            return;
        }

        $sample = implode('», «', array_slice($zero, 0, 3));
        $more = count($zero) > 3 ? ' (+'.(count($zero) - 3).' ακόμα)' : '';
        throw new LogicException(
            'Το WHMCS #'.$pending->whmcs_invoice_id.' έχει '.count($zero).' γραμμή/ές ΧΩΡΙΣ ΦΠΑ '
            .'(«'.$sample.'»'.$more.') — ήρθαν έτσι από το WHMCS (Apply Tax off στο προϊόν). Η άμεση '
            .'τιμολόγηση ΔΕΝ εκδίδει αυτόματα 0% παραστατικό: μπορεί να χρειάζεται 24% (εγχώριο) ή '
            .'να είναι απαλλαγή/ενδοκοινοτικό. Η γραμμή κρατείται — δημιούργησε προσχέδιο, όρισε τον '
            .'σωστό συντελεστή (gross-edit για να μείνει ίδιο το τελικό) και έκδωσέ το χειροκίνητα.'
        );
    }

    /**
     * UNATTENDED-ONLY: refuse to auto-issue a WHMCS invoice whose mapping carries a
     * FOLDED coupon/promotion discount (WhmcsInvoiceMapper turned a negative WHMCS
     * line into a line-level discount %). The fold produces the correct money, but
     * it cannot distinguish a genuine price discount from any other negative line
     * item (a manual credit / goodwill adjustment), so before this the negative line
     * was HELD for review. Keep that safety on the auto-file-to-AADE path: hold the
     * row so a human confirms the discount once. Deliberately NOT called on the
     * manual file / createDraft paths — an operator issuing it IS the review. WHOLE-
     * invoice; the split path doesn't fold at all.
     *
     * @param  array<string, mixed>  $mapped  WhmcsInvoiceMapper::map() output
     */
    public static function assertNoFoldedDiscountForUnattendedIssue(array $mapped, PendingWhmcsInvoice $pending): void
    {
        $discounted = $mapped['totals']['discounted_lines'] ?? [];
        if ($discounted === []) {
            return;
        }

        $sample = implode('», «', array_slice($discounted, 0, 3));
        $more = count($discounted) > 3 ? ' (+'.(count($discounted) - 3).' ακόμα)' : '';
        throw new LogicException(
            'Το WHMCS #'.$pending->whmcs_invoice_id.' έχει έκπτωση coupon/promotion που ενσωματώθηκε '
            .'σε γραμμή («'.$sample.'»'.$more.'). Η άμεση τιμολόγηση ΔΕΝ εκδίδει αυτόματα παραστατικό '
            .'με ενσωματωμένη έκπτωση — δημιούργησε προσχέδιο, επιβεβαίωσε την έκπτωση και έκδωσέ το '
            .'χειροκίνητα.'
        );
    }
}
