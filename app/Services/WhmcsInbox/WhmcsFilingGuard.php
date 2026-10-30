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
 *     total, and createDraft is the operator's review path — so ONLY file()
 *     (i.e. the unattended whmcs:auto-issue) calls it.
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
     * Refuse a WHMCS invoice whose mapped value is 0 (every line 0 — e.g. a free .gr
     * domain transfer): there is nothing to file, AADE won't take it, and creating
     * the invoice first left an orphan all-zero draft. The ingestor already closes
     * such rows as «resolved»; this is the filing-side backstop.
     *
     * @param  array<string, mixed>  $mapped  WhmcsInvoiceMapper::map() output
     */
    public static function assertNotZeroValue(array $mapped, PendingWhmcsInvoice $pending): void
    {
        // A negative (promo/credit) line nets the value to 0 too — that hold
        // (assertPayloadFilable, «αρνητικό ποσό») is the more useful message.
        if (abs((float) ($mapped['totals']['gross_total'] ?? 0.0)) > 0.005
            || ($mapped['totals']['negative_lines'] ?? []) !== []) {
            return;
        }

        throw new LogicException(
            'Το WHMCS #'.$pending->whmcs_invoice_id.' είναι μηδενικής αξίας (π.χ. δωρεάν μεταφορά .gr '
            .'domain) — δεν εκδίδεται παραστατικό 0 €. Αρχειοθέτησέ τη γραμμή στα εισερχόμενα αν δεν '
            .'χρειάζεται (ή διόρθωσε το ποσό στο WHMCS/MyCS).'
        );
    }

    /**
     * Refuse a WHMCS «Add Funds» (wallet top-up) before any draft/ΑΑ exists: a prepayment
     * is not a sale — its παραστατικό is issued when the credit is used (owner,
     * 2026-10-30). The ingestor closes a pure top-up and holds a mix; this is the
     * filing-side backstop. A mix is refused only unattended — the operator's draft is
     * where the Add Funds line gets removed (`$allowMixed`).
     */
    public static function assertNotAddFunds(PendingWhmcsInvoice $pending, bool $allowMixed = false): void
    {
        $shape = PendingWhmcsInvoice::addFundsShape(is_array($pending->payload) ? $pending->payload : []);
        if ($shape === null || ($allowMixed && $shape === PendingWhmcsInvoice::ADD_FUNDS_MIXED)) {
            return;
        }

        throw new LogicException('Το WHMCS #'.$pending->whmcs_invoice_id.': '.($shape === PendingWhmcsInvoice::ADD_FUNDS_ONLY
            ? PendingWhmcsInvoice::ADD_FUNDS_NOTE.' Αρχειοθέτησέ το στα εισερχόμενα.'
            : PendingWhmcsInvoice::ADD_FUNDS_MIXED_REASON));
    }

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
     * The VAT is reconciled against WHMCS `tax` always; the gross against `total`
     * only when it is present and non-zero (a credit-applied invoice reports a
     * reduced/zero total, so it can't be compared there).
     *
     * @param  array<string, mixed>  $mapped
     */
    public static function assertTotalsReconcile(array $mapped, PendingWhmcsInvoice $pending): void
    {
        $lineCount = count($mapped['lines'] ?? []);
        $tolerance = self::TOTALS_BASE_TOLERANCE + self::TOTALS_PER_LINE_TOLERANCE * $lineCount;

        // The VAT WHMCS charged must match the VAT we file. Checked FIRST and
        // regardless of `total`: a credit-applied WHMCS invoice reports total =
        // subtotal + tax − credit (0 when paid fully from credit), which made the
        // gross check below skip — and a net/gross mix-up then filed to AADE with
        // no guard (WHMCS #32488: VAT 51,87 filed vs 64,32 charged). `tax` is never
        // reduced by credit, so this catches the wrong split in every case.
        $whmcsTax = (float) ($mapped['source']['whmcs_tax'] ?? 0.0);
        $vat = (float) ($mapped['totals']['vat_total'] ?? 0.0);

        // WHMCS REPORTED zero VAT (a tax-exempt / foreign client: tax 0 even on
        // taxed=1 lines) but the mapping would file VAT — either backed out of the
        // amount (gross toggle → the customer is invoiced VAT they were never
        // charged) or added on top (a total WHMCS never billed). Both are wrong; the
        // right document is 0% with an exemption reason, an operator call. Every
        // other check is skipped at tax 0 / taxrate 0, so this was the hole.
        if (! empty($mapped['source']['whmcs_tax_reported']) && $whmcsTax <= 0.005 && $vat > 0.005) {
            throw new LogicException(sprintf(
                'Το WHMCS #%d δεν χρέωσε ΦΠΑ (πιθανώς απαλλαγμένος πελάτης / εξωτερικού), αλλά το '
                .'ekdosi θα εξέδιδε ΦΠΑ %s €. Η γραμμή κρατείται — εξέδωσέ το χειροκίνητα με 0%% και '
                .'την κατάλληλη αιτία απαλλαγής (ή διόρθωσε τον ΦΠΑ στο WHMCS).',
                $pending->whmcs_invoice_id,
                number_format($vat, 2, ',', '.'),
            ));
        }
        if ($whmcsTax > 0.005 && abs($vat - $whmcsTax) > $tolerance) {
            throw new LogicException(sprintf(
                'Το WHMCS #%d δεν συμφωνεί στον ΦΠΑ: το ekdosi υπολόγισε ΦΠΑ %s € αλλά το WHMCS '
                .'χρέωσε %s € (διαφορά %s €). Πιθανή αιτία: σύγχυση καθαρών/μικτών ποσών ή '
                .'διαφορετικός συντελεστής. Η γραμμή κρατείται — έλεγξέ το και εξέδωσέ το '
                .'χειροκίνητα.',
                $pending->whmcs_invoice_id,
                number_format($vat, 2, ',', '.'),
                number_format($whmcsTax, 2, ',', '.'),
                number_format(abs($vat - $whmcsTax), 2, ',', '.'),
            ));
        }

        $whmcsTotal = (float) ($mapped['source']['whmcs_total'] ?? 0.0);
        $gross = (float) ($mapped['totals']['gross_total'] ?? 0.0);

        // The applied credit is known (feed ≥ 0.54.0 / native GetInvoice): the
        // document value is EXACTLY total + credit — reconcile strictly against it.
        $credit = $mapped['source']['whmcs_credit'] ?? null;
        if ($credit !== null) {
            $documentTotal = $whmcsTotal + (float) $credit;
            if ($documentTotal > 0.005 && abs($gross - $documentTotal) > $tolerance) {
                self::throwTotalsMismatch($pending, $gross, $documentTotal);
            }

            return;
        }

        if ($whmcsTotal <= 0.005) {
            return;   // no usable WHMCS total to reconcile against (e.g. paid from credit)
        }

        // Partially credit-applied: WHMCS total = subtotal + tax − credit, and the
        // feed has no `credit`. The document value is then subtotal + tax (net
        // amounts) or subtotal (gross amounts) — accept those too. A net/gross
        // mix-up can't slip through here: the VAT check above already caught it.
        $whmcsSubtotal = (float) ($mapped['source']['whmcs_subtotal'] ?? 0.0);
        if ($whmcsSubtotal > 0.005) {
            foreach ([$whmcsSubtotal + $whmcsTax, $whmcsSubtotal] as $documentValue) {
                if (abs($gross - $documentValue) <= $tolerance) {
                    return;
                }
            }
        }

        if (abs($gross - $whmcsTotal) > $tolerance) {
            self::throwTotalsMismatch($pending, $gross, $whmcsTotal);
        }
    }

    private static function throwTotalsMismatch(PendingWhmcsInvoice $pending, float $gross, float $whmcsTotal): never
    {
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

    /**
     * The invoice's own tax ratio contradicts the tax mode WHMCS declares
     * (`taxtype`, ekdosi_bridge ≥ 0.54.0) — the WHMCS setting changed since, or the
     * invoice was hand-edited. Filing unattended would bet on one of the two; HOLD
     * it for the operator instead. WHOLE-invoice, unattended path.
     *
     * @param  array<string, mixed>  $mapped
     */
    public static function assertTaxTypeConsistent(array $mapped, PendingWhmcsInvoice $pending): void
    {
        if (empty($mapped['totals']['taxtype_conflict'])) {
            return;
        }

        throw new LogicException(sprintf(
            'Το WHMCS #%d δηλώνει ΦΠΑ «%s» αλλά τα ποσά του τιμολογίου αντιστοιχούν στο αντίθετο '
            .'(καθαρά/μικτά). Πιθανή αιτία: άλλαξε η ρύθμιση ΦΠΑ του WHMCS ή το τιμολόγιο '
            .'διορθώθηκε χειροκίνητα. Η γραμμή κρατείται — έλεγξέ το και εξέδωσέ το χειροκίνητα.',
            $pending->whmcs_invoice_id,
            (string) ($mapped['source']['whmcs_taxtype'] ?? ''),
        ));
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
        // Belt-and-braces, independent of how the lines were mapped: WHMCS itself
        // REPORTED zero VAT on an invoice that charges something (a tax-exempt /
        // foreign client, or a product left untaxed). Whatever ekdosi would file —
        // 0% (legal only with the right exemption) or 24% (VAT the client was never
        // charged) — a human decides; never auto-file it. Waits in the inbox.
        $chargesSomething = (float) ($mapped['totals']['gross_total'] ?? 0.0) > 0.005;
        if (! empty($mapped['source']['whmcs_tax_reported'])
            && (float) ($mapped['source']['whmcs_tax'] ?? 0.0) <= 0.005
            && $chargesSomething) {
            throw new LogicException(
                'Το WHMCS #'.$pending->whmcs_invoice_id.' δεν χρέωσε ΦΠΑ. Η άμεση τιμολόγηση ΔΕΝ εκδίδει '
                .'αυτόματα τιμολόγιο χωρίς ΦΠΑ (μπορεί να χρειάζεται 24% ή απαλλαγή με αιτία). Η γραμμή '
                .'κρατείται στα εισερχόμενα — έλεγξέ το και έκδωσέ το χειροκίνητα.'
            );
        }

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
