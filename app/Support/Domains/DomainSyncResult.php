<?php

namespace App\Support\Domains;

use App\Enums\DomainStatus;

/**
 * What a DomainRegistrar::syncDomain() pull found at the registry — the
 * REGISTRAR-truth side of the two clocks (docs/domains/README.md §3.4/§6.4).
 * `status` is the adapter's confident mapping to our lifecycle; null = the
 * registrar state has no safe mapping (we then keep the local status and
 * stash `rawStatus` in module_meta for the operator).
 *
 * Immutable.
 */
final class DomainSyncResult
{
    /**
     * @param  ?string  $expiresAt  Y-m-d, null when the registrar did not report one.
     * @param  list<string>  $nameservers
     */
    public function __construct(
        public readonly ?string $expiresAt = null,
        public readonly array $nameservers = [],
        public readonly ?string $registrarDomainId = null,
        public readonly ?DomainStatus $status = null,
        public readonly ?string $rawStatus = null,
    ) {}
}
