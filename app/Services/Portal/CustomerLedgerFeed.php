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
     *     company:string, customer:string, afm:?string, role:string,
     *     balance:float, credit:float, owed:float, oldest_unpaid_days:?int,
     *     has_activity:bool, rows:list<array<string,mixed>>
     * }>
     */
    public function forLogin(CustomerUser $login): array
    {
        $out = [];
        foreach ($this->boundary->grantedTargets($login) as $grant) {
            $result = $this->builder->build($grant->customer);
            $balance = round((float) $result->stats['balance'], 2);

            $out[] = [
                'company' => (string) $grant->company->name,
                'customer' => (string) $grant->customer->name,
                'afm' => $grant->customer->afm,
                'role' => (string) $grant->role,
                'balance' => $balance,
                // Split for the header: a positive balance is owed; a negative one
                // is the customer's credit (overpaid / prepaid on account).
                'owed' => max($balance, 0.0),
                'credit' => max(-$balance, 0.0),
                'oldest_unpaid_days' => $result->stats['oldest_unpaid_days'],
                'has_activity' => $result->hasAnyActivity(),
                'rows' => $this->projectRows($result->ledger),
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
     * @return list<array<string,mixed>>
     */
    private function projectRows(array $ledger): array
    {
        return array_map(fn (array $e): array => [
            'date' => $e['date'],
            'label' => (string) $e['reference'],
            'kind' => $this->kind($e),
            'debit' => round((float) $e['debit'], 2),
            'credit' => round((float) $e['credit'], 2),
            'running_balance' => round((float) $e['running_balance'], 2),
        ], $ledger);
    }

    /**
     * Row kind → badge. An `invoice`-type row landing in the CREDIT column is a
     * credit note; in the DEBIT column, a normal invoice.
     */
    private function kind(array $e): string
    {
        return match ($e['type']) {
            'refund' => 'refund',
            'payment' => 'payment',
            default => ((float) $e['credit'] > 0) ? 'credit' : 'invoice',
        };
    }
}
