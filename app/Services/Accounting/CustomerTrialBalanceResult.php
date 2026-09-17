<?php

namespace App\Services\Accounting;

/**
 * Result of the «Ισοζύγιο Πελατών» report: the per-customer rows plus the
 * footed column totals. Σ(closing) reconciles with the receivables figure the
 * dashboard / aged-receivables show (same tracked-balance basis).
 */
class CustomerTrialBalanceResult
{
    /** @param list<CustomerTrialBalanceRow> $rows */
    public function __construct(
        public readonly array $rows,
        public readonly string $periodLabel,
    ) {}

    public function totalOpening(): float
    {
        return $this->sum(fn (CustomerTrialBalanceRow $r): float => $r->opening);
    }

    public function totalDebit(): float
    {
        return $this->sum(fn (CustomerTrialBalanceRow $r): float => $r->debit);
    }

    public function totalCredit(): float
    {
        return $this->sum(fn (CustomerTrialBalanceRow $r): float => $r->credit);
    }

    public function totalClosing(): float
    {
        return $this->sum(fn (CustomerTrialBalanceRow $r): float => $r->closing);
    }

    /** @param callable(CustomerTrialBalanceRow): float $pick */
    private function sum(callable $pick): float
    {
        return round(array_sum(array_map($pick, $this->rows)), 2);
    }
}
