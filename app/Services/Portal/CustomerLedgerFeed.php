<?php

namespace App\Services\Portal;

use App\Models\CustomerUser;
use App\Services\CustomerLedger\CustomerLedgerBuilder;

/**
 * «Η καρτέλα μου» for the portal — one financial statement per (company,
 * customer) the login is granted. It does NOT compute any money itself: it reads
 * the SAME canonical engine the operator Καρτέλα uses (`CustomerLedgerBuilder`),
 * so a customer sees exactly the balance and ledger the operator sees, and the
 * `GET_CUSTOMER_BALANCE` / credit-term / on-account / credit-note semantics stay
 * in one place. The grant boundary is reused from CustomerDocumentFeed
 * (`grantedTargets`) so documents and ledger can never diverge on scope.
 *
 * Read-only: no «pay» action here — that attaches when the payment-gateway pillar
 * lands. A negative balance already surfaces as a credit («πιστωτικό υπόλοιπο»),
 * the seat the future prepaid-credit feature drops into.
 */
class CustomerLedgerFeed
{
    public function __construct(
        private CustomerDocumentFeed $boundary,
        private CustomerLedgerBuilder $builder,
    ) {}

    /**
     * One statement per active grant. Balance is per (company, customer) — never
     * summed across companies (a login with grants in two companies has two
     * independent statements).
     *
     * @return list<array{
     *     company_id:int, customer_id:int, company:string, customer:string, afm:?string, role:string,
     *     balance:float, credit:float, owed:float, oldest_unpaid_days:?int,
     *     rows:list<array<string,mixed>>
     * }>
     */
    public function forLogin(CustomerUser $login): array
    {
        $out = [];
        foreach ($this->boundary->grantedTargets($login) as $grant) {
            $result = $this->builder->build($grant->customer);
            // Read the engine's already-2dp balance straight — the feed never
            // massages money, it only SPLITS the balance into owed vs credit.
            $balance = (float) $result->stats['balance'];

            // Which of this customer's documents are openable online — so a ledger
            // row links to its view ONLY when the doc-show route would allow it (the
            // ledger admits some rows, e.g. legacy/credit-note drafts, that are not
            // customer-visible; linking those would 404).
            $visibleIds = $this->boundary->visibleDocumentIdSet(
                (int) $grant->company_id,
                (int) $grant->customer_id,
            );

            $out[] = [
                'company_id' => (int) $grant->company_id,
                'customer_id' => (int) $grant->customer_id,
                'company' => (string) $grant->company->name,
                'customer' => (string) $grant->customer->name,
                'afm' => $grant->customer->afm,
                'role' => (string) $grant->role,
                'balance' => $balance,
                // A positive balance is owed; a negative one is the customer's
                // credit (overpaid / prepaid on account).
                'owed' => max($balance, 0.0),
                'credit' => max(-$balance, 0.0),
                'oldest_unpaid_days' => $result->stats['oldest_unpaid_days'],
                // Chronological (old→new) — «Η καρτέλα μου» reads like a statement,
                // consistent with the operator table + the CSV/PDF export.
                'rows' => $this->projectRows($result->chronologicalLedger(), $visibleIds),
            ];
        }

        return $out;
    }

    /**
     * Customer-safe projection of the ledger rows — date, a label, a kind (for the
     * badge), the debit/credit and the running balance. Drops operator-internal
     * fields (payment ids, allocation drill-downs, mydata state).
     *
     * @param  list<array<string,mixed>>  $ledger
     * @param  array<int,true>  $visibleIds  invoice ids openable online (link gate)
     * @return list<array<string,mixed>>
     */
    private function projectRows(array $ledger, array $visibleIds): array
    {
        return array_map(fn (array $e): array => [
            'date' => $e['date'],
            'label' => $this->label($e),
            'kind' => $this->kind($e),
            // Only a real document row (invoice / credit note / proforma) links to
            // its online view — payment/refund rows have no document page — AND only
            // when that document is actually customer-visible (else the link 404s).
            // The doc-show route re-checks the grant, so exposing the id is safe.
            'invoice_id' => (($e['type'] ?? null) === 'invoice' && isset($e['invoice_id']) && isset($visibleIds[(int) $e['invoice_id']]))
                ? (int) $e['invoice_id']
                : null,
            'debit' => round((float) $e['debit'], 2),
            'credit' => round((float) $e['credit'], 2),
            'running_balance' => round((float) $e['running_balance'], 2),
        ], $ledger);
    }

    /**
     * Customer-facing label. Strips internal «#<id>» suffixes (raw sequential
     * payment/invoice ids the operator reference carries — «Πληρωμή #123»,
     * «#456» for a null-invcode invoice) so the statement never discloses global
     * ids; keeps human references (invcodes, grouped-receipt refs). Falls back to
     * a type word when nothing meaningful remains.
     */
    private function label(array $e): string
    {
        $clean = trim((string) preg_replace('/\s*#\d+$/', '', (string) $e['reference']));
        if ($clean !== '') {
            return $clean;
        }

        return match ($this->kind($e)) {
            'payment' => 'Πληρωμή',
            'refund' => 'Επιστροφή χρημάτων',
            'credit' => 'Πιστωτικό',
            'proforma' => 'Προτιμολόγιο',
            default => 'Παραστατικό',
        };
    }

    /**
     * Row kind → badge. An `invoice`-type row landing in the CREDIT column is a
     * credit note; in the DEBIT column, a normal invoice.
     */
    private function kind(array $e): string
    {
        // A προτιμολόγιο is kept on the statement once it carries money, but it is
        // NOT a tax document — it gets its own badge rather than reading as one.
        if (($e['is_proforma'] ?? false) === true) {
            return 'proforma';
        }

        return match ($e['type']) {
            'refund' => 'refund',
            'payment' => 'payment',
            default => ((float) $e['credit'] > 0) ? 'credit' : 'invoice',
        };
    }
}
