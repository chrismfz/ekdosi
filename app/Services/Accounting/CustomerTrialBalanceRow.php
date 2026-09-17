<?php

namespace App\Services\Accounting;

/**
 * One customer's line in the «Ισοζύγιο Πελατών» (#4): opening carry-over +
 * period debit (χρέωση) − period credit (πίστωση) = closing. All figures use
 * the receivables/Καρτέλα basis (see CustomerLedgerBuilder::periodBalances).
 */
class CustomerTrialBalanceRow
{
    public function __construct(
        public readonly int $customerId,
        public readonly string $customerName,
        public readonly ?string $afm,
        public readonly float $opening,
        public readonly float $debit,
        public readonly float $credit,
        public readonly float $closing,
    ) {}
}
