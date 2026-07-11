<?php

namespace App\Support;

/**
 * The single definition of a "live" invoice for money/reporting purposes:
 * NOT cancelled locally AND NOT cancelled at myDATA. Centralised so the
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
    public static function live($query, string $prefix = '')
    {
        $state = $prefix.'mydata_state';
        $local = $prefix.'local_status';

        return $query
            ->where($local, '!=', 'cancelled')
            ->where(fn ($q) => $q
                ->whereNull($state)
                ->orWhere($state, '!=', 'CANCELLED'));
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
}
