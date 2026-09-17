<?php

namespace App\Services\Accounting;

use App\Models\Company;
use App\Models\Customer;
use App\Services\CustomerLedger\CustomerLedgerBuilder;
use Carbon\CarbonInterface;

/**
 * «Ισοζύγιο Πελατών» (#4) — customer trial balance for a period: per customer,
 * `Υπόλοιπο μεταφοράς | Χρέωση περιόδου | Πίστωση περιόδου | Τελικό`, with footed
 * totals. Read-only.
 *
 * Reuses CustomerLedgerBuilder::periodBalances per customer (like
 * AgedReceivablesReport reuses buildAgingBlock), so each row's math is IDENTICAL
 * to the Καρτέλα — one customer's closing equals their Καρτέλα balance, and
 * Σ(closing) reconciles with the dashboard/aged-receivables figure.
 */
class CustomerTrialBalanceReport
{
    public function build(Company $tenant, CarbonInterface $start, CarbonInterface $end): CustomerTrialBalanceResult
    {
        // Candidates: customers that ever transacted (any invoice or payment).
        // periodBalances re-applies the live/draft/credit-term filters and the
        // [start, end] window; all-zero rows are dropped below — so a loose
        // candidate set only risks computing a zero row, never a wrong figure.
        $customers = Customer::query()
            ->where('company_id', $tenant->getKey())
            ->where(fn ($q) => $q->whereHas('invoices')->orWhereHas('payments'))
            ->get();

        $builder = app(CustomerLedgerBuilder::class);
        $rows = [];

        foreach ($customers as $customer) {
            $b = $builder->periodBalances($customer, $start, $end);

            // A customer with neither a carry-over nor any movement in the period
            // is not part of this period's balance — skip.
            if (abs($b['opening']) < 0.005 && abs($b['debit']) < 0.005
                && abs($b['credit']) < 0.005 && abs($b['closing']) < 0.005) {
                continue;
            }

            $rows[] = new CustomerTrialBalanceRow(
                customerId: (int) $customer->getKey(),
                customerName: (string) $customer->name,
                afm: $customer->afm,
                opening: $b['opening'],
                debit: $b['debit'],
                credit: $b['credit'],
                closing: $b['closing'],
            );
        }

        // Biggest closing balance first; name as a deterministic tiebreak.
        usort(
            $rows,
            fn (CustomerTrialBalanceRow $a, CustomerTrialBalanceRow $b): int => [$b->closing, $a->customerName] <=> [$a->closing, $b->customerName],
        );

        return new CustomerTrialBalanceResult(
            rows: $rows,
            periodLabel: $start->format('d/m/Y').' – '.$end->format('d/m/Y'),
        );
    }
}
