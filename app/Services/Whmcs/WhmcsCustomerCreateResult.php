<?php

namespace App\Services\Whmcs;

use App\Models\Customer;

/**
 * Outcome of WhmcsCustomerCreator::createForPending.
 *
 * `source`: 'aade' (created from the GSIS registry), 'whmcs' (created from the
 * WHMCS-typed data because GSIS was unavailable / the ΑΦΜ is foreign),
 * 'existing' (an ekdosi customer with this ΑΦΜ already existed), 'deleted_owner'
 * (a SOFT-DELETED customer owns this ΑΦΜ — restore it; `customer` is that row,
 * `created` false, nothing linked), or 'no_afm' (the WHMCS invoice carries no
 * ΑΦΜ — nothing to create from).
 *
 * `discrepancies`: per-field conflicts where the official GSIS value DIFFERED
 * from the value the customer typed in WHMCS and the GSIS (correct) value was
 * kept — so the UI can warn the operator what changed (e.g. WHMCS «ΕΠΩΝΥΜΙΑ Χ
 * Α.Ε.» → ΑΑΔΕ «ΕΠΩΝΥΜΙΑ ΧΡΗΣΤΟΥ ΚΑΙ ΣΙΑ Α.Ε.»). Empty when GSIS didn't run,
 * data agreed, or only filled gaps. Each entry: {field, whmcs, aade}.
 *
 * @phpstan-type Discrepancy array{field: string, whmcs: string, aade: string}
 */
final readonly class WhmcsCustomerCreateResult
{
    /**
     * @param  list<array{field: string, whmcs: string, aade: string}>  $discrepancies
     */
    public function __construct(
        public ?Customer $customer,
        public bool $created,
        public string $source,
        public array $discrepancies = [],
    ) {}
}
