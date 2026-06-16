<?php

namespace App\Services\Accounting;

/**
 * Aged-receivables snapshot: one row per customer with an open balance, plus the
 * footed column totals an accountant reads at the bottom.
 */
final readonly class AgedReceivablesResult
{
    /** @param  list<AgedReceivablesRow>  $rows */
    public function __construct(public array $rows) {}

    public function isEmpty(): bool
    {
        return $this->rows === [];
    }

    public function customerCount(): int
    {
        return count($this->rows);
    }

    public function total0_30(): float
    {
        return $this->sum(fn (AgedReceivablesRow $r) => $r->b0_30);
    }

    public function total31_60(): float
    {
        return $this->sum(fn (AgedReceivablesRow $r) => $r->b31_60);
    }

    public function total61_90(): float
    {
        return $this->sum(fn (AgedReceivablesRow $r) => $r->b61_90);
    }

    public function total90plus(): float
    {
        return $this->sum(fn (AgedReceivablesRow $r) => $r->b90plus);
    }

    public function grandTotal(): float
    {
        return $this->sum(fn (AgedReceivablesRow $r) => $r->total);
    }

    private function sum(callable $field): float
    {
        return round(array_sum(array_map($field, $this->rows)), 2);
    }
}
