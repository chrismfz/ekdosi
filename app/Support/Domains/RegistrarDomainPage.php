<?php

namespace App\Support\Domains;

/**
 * One page of a registrar's account domain listing (Πυλώνας A / A2c import).
 * `total` is the registrar-reported account total when known — the pager stops
 * on it, or on the first short/empty page when the registrar doesn't say.
 *
 * Immutable.
 */
final class RegistrarDomainPage
{
    public function __construct(
        /** @var list<RegistrarDomainRecord> */
        public readonly array $records = [],
        public readonly ?int $total = null,
    ) {}
}
