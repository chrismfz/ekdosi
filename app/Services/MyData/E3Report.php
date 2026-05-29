<?php

namespace App\Services\MyData;

/**
 * Aggregated Ε3 figures for a period, from AADE `RequestE3Info` (E7). A flat
 * list of per-(type, category) rows + the grand total of the classification
 * values. Read-only — this is AADE's own Ε3 aggregation for our ΑΦΜ.
 */
final readonly class E3Report
{
    /**
     * @param  list<E3ReportRow>  $rows
     */
    public function __construct(
        public string $from,         // dd/MM/yyyy queried window
        public string $to,
        public array $rows,
        public int $docCount,        // total E3Info entries scanned
        public float $total,         // Σ of all class values
    ) {}

    public function isEmpty(): bool
    {
        return $this->rows === [];
    }
}
