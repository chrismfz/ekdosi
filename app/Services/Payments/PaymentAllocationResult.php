<?php

namespace App\Services\Payments;

/**
 * Outcome of one «έμβασμα/είσπραξη»: how a received amount was split across
 * invoices (FIFO) plus any on-account remainder. All rows share `reference`.
 */
final readonly class PaymentAllocationResult
{
    /**
     * @param  list<array{invcode: string, amount: float}>  $allocations
     */
    public function __construct(
        public string $reference,
        public float $total,
        public array $allocations,
        public float $onAccount,
    ) {}

    public function allocatedToInvoices(): float
    {
        return round(array_sum(array_column($this->allocations, 'amount')), 2);
    }
}
