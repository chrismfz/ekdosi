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
}
