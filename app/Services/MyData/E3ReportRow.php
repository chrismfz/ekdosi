<?php

namespace App\Services\MyData;

/**
 * One aggregated Ε3 line: a classification type (E3_*) + category, the summed
 * value across the period, and how many documents contributed.
 */
final readonly class E3ReportRow
{
    public function __construct(
        public string $classType,       // E3_* code
        public ?string $classCategory,  // category1_* / category2_* …
        public float $value,            // Σ V_Class_Value
        public int $count,              // contributing E3Info entries
    ) {}
}
