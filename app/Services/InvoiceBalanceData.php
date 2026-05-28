<?php

namespace App\Services;

use App\Enums\PaymentStatus;

/**
 * Immutable money snapshot of an invoice, produced by InvoiceBalance.
 *
 *   owed    = gross − credited
 *   balance = owed − paid
 */
readonly class InvoiceBalanceData
{
    public function __construct(
        public float $gross,
        public float $credited,
        public float $paid,
        public float $owed,
        public float $balance,
        public PaymentStatus $status,
    ) {}
}
