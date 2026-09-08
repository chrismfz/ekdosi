<?php

namespace App\Services;

use App\Models\Company;
use Illuminate\Database\ConnectionInterface;
use RuntimeException;

/**
 * Allocates the next per-company quote number atomically.
 *
 * Deliberately a SEPARATE counter (companies.quote_counter) from the legal ΑΑ
 * (invoice_types.invcount owned by InvoiceNumberer): a quote is not a legal
 * document and must never advance — or be confused with — the invoice
 * sequence. The lock discipline mirrors InvoiceNumberer: SELECT ... FOR UPDATE
 * + raw increment, inside a caller-held transaction, so concurrent quote
 * creation for the same company can't collide on the number.
 *
 * Format: "ΠΡ-{n}" (no padding, no fiscal year) — plain and human-readable.
 */
final class QuoteNumberer
{
    public function __construct(
        private readonly ConnectionInterface $db,
    ) {}

    /**
     * Allocate the next quote code for the given company.
     *
     * MUST run inside a DB transaction held open by the caller until the quote
     * row is persisted — a failed INSERT then rolls back the counter bump,
     * leaving no gap (same invariant as InvoiceNumberer).
     */
    public function allocate(Company $company): string
    {
        if (! $this->db->transactionLevel()) {
            throw new RuntimeException(
                'QuoteNumberer::allocate() must run inside a DB transaction. '
                . 'Wrap quote creation in DB::transaction(...).'
            );
        }

        // Reserve under a row lock so concurrent allocate() calls for the same
        // company serialise on this row until the caller commits.
        $row = $this->db->table('companies')
            ->where('id', $company->getKey())
            ->lockForUpdate()
            ->first(['quote_counter']);

        if (! $row) {
            throw new RuntimeException(sprintf(
                'No company with id=%d. Cannot allocate quote number.',
                $company->getKey(),
            ));
        }

        $next = ((int) ($row->quote_counter ?? 0)) + 1;

        // Raw UPDATE (not Eloquent ->increment) — no model events / updated_at
        // churn on the hot path. The lock above still covers this write.
        $this->db->table('companies')
            ->where('id', $company->getKey())
            ->update(['quote_counter' => $next]);

        return 'ΠΡ-' . $next;
    }
}
