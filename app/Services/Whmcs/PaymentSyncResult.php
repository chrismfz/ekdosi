<?php

namespace App\Services\Whmcs;

/**
 * Outcome of one tenant's WHMCS→ekdosi payment sync run.
 *   checked  = open WHMCS-linked invoices we queried WHMCS for
 *   recorded = payments actually written (the invoice was Paid at WHMCS)
 *   total    = Σ recorded payment amounts
 */
readonly class PaymentSyncResult
{
    public function __construct(
        public int $checked,
        public int $recorded,
        public float $total,
    ) {}
}
