<?php

namespace App\Support;

use App\Models\Invoice;

/**
 * The single definition of a "live" invoice for money/reporting purposes:
 * NOT cancelled locally AND NOT cancelled at myDATA AND NOT of an informal
 * (non-fiscal) series. Centralised so the
 * AADE-state + local-status semantics live in ONE place — a future
 * terminal state (or a change to the null=draft convention) is a one-line
 * edit, not a hunt across InvoiceBalance / DashboardMetrics / the ledger
 * (the duplicated-predicate drift that the branch review surfaced).
 *
 * Works on both Eloquent and query builders (both support whereNull /
 * where / nested closures). Pass $prefix = 'invoices.' when the query
 * joins other tables and the columns must be qualified.
 *
 * NOTE: this does NOT exclude credit notes (credited_invoice_id) or apply
 * the cash-term rule — those are context-specific (income excludes credit
 * notes; the ledger treats them as reductions), so they stay per-site.
 */
class InvoiceScope
{
    /**
     * Documents the CUSTOMER may settle: issued (`local_status=active`) or an
     * offered «προτιμολόγιο» (a draft the operator finalised and put in front of
     * them). The SQL twin of {@see Invoice::isCustomerPayable()}.
     *
     * THE single definition. It was originally re-typed at each call site, and the
     * one copy that got missed — PaymentAllocator::allocateToInvoice() — threw on a
     * proforma, rolling back the whole settle transaction: the bank had taken the
     * money and we wrote no Payment, no intent transition and no «Log πύλης» row.
     * Every site that decides «may money land here» now shares this one predicate,
     * so the next widening cannot miss one.
     */
    public static function customerSettleable($query, string $prefix = '')
    {
        $local = $prefix.'local_status';
        $offered = $prefix.'offered_at';

        // An informal (non-fiscal) document is never a payment target.
        self::excludeInformal($query, $prefix);

        return $query
            // Carried here, not left to the call sites: this helper exists precisely
            // to stop «one site missed part of the predicate» drift, and its PHP twin
            // refuses an AADE-cancelled document. A future site using the helper alone
            // would otherwise let money land on a voided invoice.
            ->where(fn ($q) => $q->whereNull($prefix.'mydata_state')
                ->orWhere($prefix.'mydata_state', '!=', 'CANCELLED'))
            ->where(fn ($q) => $q
                ->where($local, 'active')
                ->orWhere(fn ($o) => $o->where($local, 'draft')->whereNotNull($offered)));
    }

    public static function live($query, string $prefix = '')
    {
        $state = $prefix.'mydata_state';
        $local = $prefix.'local_status';

        return self::excludeInformal($query
            ->where($local, '!=', 'cancelled')
            ->where(fn ($q) => $q
                ->whereNull($state)
                ->orWhere($state, '!=', 'CANCELLED')), $prefix);
    }

    /**
     * Drop documents of an INFORMAL (non-fiscal) series — tests and our own internal
     * services (docs/non-billable-services.md). They are not tax documents, so they
     * never count as a sale, VAT, a receivable, a reminder/dunning target or a
     * payment target. Carried by live() so every money/report site gets it at once;
     * the PHP twin is Invoice::isInformal().
     *
     * Uncorrelated `NOT IN (informal type ids)` rather than a correlated EXISTS on
     * `invoices.invoice_type_id`: it keeps working under a table alias (a whereHas
     * on a self-relation aliases `invoices`), and the explicit NULL branch keeps an
     * invoice without a type counted (a bare NOT IN would silently drop it). The
     * subquery reads trashed types too — a deleted series is still informal.
     */
    public static function excludeInformal($query, string $prefix = '')
    {
        $type = $prefix.'invoice_type_id';

        return $query->where(fn ($q) => $q
            ->whereNull($type)
            ->orWhereNotIn($type, fn ($sub) => $sub->select('id')->from('invoice_types')->where('is_informal', true)));
    }

    /**
     * A credit note is EITHER correlated (`credited_invoice_id` set — the
     * IssueCreditNote path) OR a standalone credit-type document
     * (`invoice_types.is_credit` — how the ETL imports legacy ΠΙΣ/returns, which
     * carry NO `credited_invoice_id`). Both must be recognised, otherwise a
     * tenant with imported legacy credit notes over-counts turnover / over-
     * declares output VAT. Mirrors `Invoice::isCreditNote()`.
     *
     * Works on Eloquent AND query builders (both support whereNull / a nested
     * closure / a correlated whereExists). Assumes the query's base table is
     * `invoices` with no alias (all call sites are).
     */
    public static function excludeCreditNotes($query)
    {
        return $query
            ->whereNull('credited_invoice_id')
            ->whereNotExists(fn ($q) => $q->from('invoice_types')
                ->whereColumn('invoice_types.id', 'invoices.invoice_type_id')
                ->where('invoice_types.is_credit', true));
    }

    /** The complement of excludeCreditNotes() — only the credit notes. */
    public static function onlyCreditNotes($query)
    {
        return $query->where(fn ($q) => $q
            ->whereNotNull('credited_invoice_id')
            ->orWhereExists(fn ($sub) => $sub->from('invoice_types')
                ->whereColumn('invoice_types.id', 'invoices.invoice_type_id')
                ->where('invoice_types.is_credit', true)));
    }

    /**
     * Only STANDALONE credit notes: a credit-type document (`invoice_types.is_credit`)
     * with NO `credited_invoice_id` — how the ETL imports legacy ΠΙΣ/returns. These
     * are the credit notes the SQL receivables model can't net via an original's
     * `credited_total` (there is no original), so the AR surfaces must subtract their
     * payable directly to match `CustomerLedgerBuilder` (which reduces the balance by
     * EVERY credit note). Correlated credit notes are deliberately NOT included here —
     * their reduction already flows through `credited_total`.
     *
     * Assumes the query's base table is `invoices` with no alias (all call sites are).
     */
    public static function onlyStandaloneCreditNotes($query)
    {
        return $query
            ->whereNull('credited_invoice_id')
            ->whereExists(fn ($q) => $q->from('invoice_types')
                ->whereColumn('invoice_types.id', 'invoices.invoice_type_id')
                ->where('invoice_types.is_credit', true));
    }

    /**
     * MON-5: drop UNISSUED SALE DRAFTS from the real money surfaces (turnover,
     * receivables, Καρτέλα). A draft is not an issued document, so a just-created /
     * WHMCS-staged / renewal πρόχειρο must NOT read as revenue or a receivable.
     *
     * KEPT (still counted), because they are NOT unissued sales:
     *  - credit-note drafts — IssueCreditNote issues them as a draft that ALREADY
     *    reduces the balance locally (deliberate); dropping them would un-reduce.
     *  - legacy-imported drafts (`legacy_id` set) — real historical documents the
     *    ETL backfilled as draft (mirrors LedgerBook's carve-out).
     *
     * So a row survives if it is: not-draft OR legacy OR a credit note. Columns are
     * qualified `invoices.` because the joined lookup tables (payment_methods /
     * invoice_types) ALSO carry a `legacy_id` — unqualified would be ambiguous.
     * Base table is `invoices` (no alias) at every call site.
     */
    public static function excludeUnissuedDrafts($query)
    {
        return $query->where(fn ($q) => $q
            ->where('invoices.local_status', '!=', 'draft')
            ->orWhereNotNull('invoices.legacy_id')
            ->orWhereNotNull('invoices.credited_invoice_id')
            ->orWhereExists(fn ($sub) => $sub->from('invoice_types')
                ->whereColumn('invoice_types.id', 'invoices.invoice_type_id')
                ->where('invoice_types.is_credit', true)));
    }

    /**
     * The BALANCE-surface variant of {@see excludeUnissuedDrafts()}: drop unissued
     * sale drafts EXCEPT the ones that already carry money.
     *
     * An offered προτιμολόγιο is not a receivable — the service can still be called
     * off by either side, so nothing is owed yet and it must not inflate «Απαιτήσεις».
     * But the moment the customer PAYS it, the payment is counted by every balance
     * surface while the charge was not, which drove the customer's balance and the
     * tenant receivables NEGATIVE by the paid amount: the portal told the customer
     * they held credit that availableCredit() then refused to spend. Debit and credit
     * have to move together.
     *
     * This is the SAME money-trail exception the cash-term rule already uses («plus
     * any cash-term invoice that carries a recorded payment — it then nets to zero
     * against its payment»), applied to the other kind of not-yet-owed document.
     *
     * Use this on BALANCE surfaces only (receivables, Καρτέλα, customer balance).
     * Revenue/turnover/VAT keep plain excludeUnissuedDrafts(): a paid proforma is
     * still not issued revenue.
     */
    public static function excludeUnpaidUnissuedDrafts($query)
    {
        return $query->where(fn ($q) => $q
            ->where('invoices.local_status', '!=', 'draft')
            ->orWhereNotNull('invoices.legacy_id')
            ->orWhereNotNull('invoices.credited_invoice_id')
            ->orWhereExists(fn ($sub) => $sub->from('invoice_types')
                ->whereColumn('invoice_types.id', 'invoices.invoice_type_id')
                ->where('invoice_types.is_credit', true))
            // …and the money-trail exception: it carries a payment, so the charge
            // must be counted for that payment to net against something.
            ->orWhereExists(fn ($sub) => $sub->from('payments')
                ->whereColumn('payments.invoice_id', 'invoices.id')
                ->whereNull('payments.deleted_at')));
    }

    /**
     * The complement of excludeUnissuedDrafts() — ONLY the unissued sale drafts
     * (πρόχειρα / προτιμολόγια): local draft, new-app (`legacy_id` null), NOT a
     * credit note. Feeds the «Πρόχειρα» pipeline figure so operators still see how
     * many drafts exist and their value, even though they're out of the money totals.
     */
    public static function onlyUnissuedDrafts($query)
    {
        return $query
            ->where('invoices.local_status', 'draft')
            ->whereNull('invoices.legacy_id')
            ->whereNull('invoices.credited_invoice_id')
            ->whereNotExists(fn ($sub) => $sub->from('invoice_types')
                ->whereColumn('invoice_types.id', 'invoices.invoice_type_id')
                ->where('invoice_types.is_credit', true));
    }
}
