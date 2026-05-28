<?php

namespace App\Services\Dashboard;

/**
 * Immutable income snapshot for a period: net, gross, derived VAT
 * (gross - net, output-side only), and invoice count.
 */
readonly class IncomeFigure
{
    public function __construct(
        public float $net,
        public float $gross,
        public float $vat,
        public int $count,
    ) {}
}
