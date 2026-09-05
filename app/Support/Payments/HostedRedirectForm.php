<?php

namespace App\Support\Payments;

/**
 * A signed POST the customer's browser submits to a hosted payment page
 * (Eurobank/Cardlink vPOS, and future `flow=redirect` acquirers). Built fresh at
 * redirect time by a HostedRedirectGateway — never persisted (it carries the
 * request digest, and rebuilding keeps it stable across a page refresh without
 * stashing a signed blob). The customer's browser auto-submits `fields` to
 * `action`; the outcome comes back on our signed return webhook, never here.
 */
final readonly class HostedRedirectForm
{
    /** @param array<string, string> $fields hidden form inputs (already includes the digest) */
    public function __construct(
        public string $action,
        public array $fields,
    ) {}
}
