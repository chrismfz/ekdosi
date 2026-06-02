<?php

namespace App\Services\Whmcs;

use App\Models\Customer;

/**
 * Outcome of WhmcsCustomerCreator::createForPending.
 *
 * `source`: 'aade' (created from the GSIS registry), 'whmcs' (created from the
 * WHMCS-typed data because GSIS was unavailable / the ΑΦΜ is foreign),
 * 'existing' (an ekdosi customer with this ΑΦΜ already existed), or 'no_afm'
 * (the WHMCS invoice carries no ΑΦΜ — nothing to create from).
 */
final readonly class WhmcsCustomerCreateResult
{
    public function __construct(
        public ?Customer $customer,
        public bool $created,
        public string $source,
    ) {}
}
