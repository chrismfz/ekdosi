<?php

namespace App\Services\Leads;

/**
 * One operator's line of the «Απολογισμός πωλήσεων» (null user = rows with no
 * operator: system-written or an unassigned lead).
 */
final class SalesOperatorRow
{
    /**
     * @param  array<string, int>  $counters  keyed like SalesActivityResult::COLUMNS
     */
    public function __construct(
        public readonly ?int $userId,
        public readonly string $name,
        public readonly array $counters,
    ) {}

    public function get(string $key): int
    {
        return $this->counters[$key] ?? 0;
    }
}
