<?php

namespace App\Contracts;

use App\Support\Domains\AvailabilityResult;
use App\Support\Domains\DomainRegistrarCapabilities;
use App\Support\Domains\DomainRegistrarCredentials;

/**
 * One registrar adapter per provider (Πυλώνας A) — the domains sibling of
 * EInvoiceProviderTransport: it knows ONLY how to talk to one registrar's API
 * (Openprovider REST, grEPP XML-over-HTTPS, …). Lifecycle, billing and
 * persistence live elsewhere. Adding a registrar = one class implementing this
 * + one line in config/ekdosi.php → domains.registrars.
 *
 * A0 ships the seam with the methods the foundation actually exercises; the
 * state-changing surface (register/renew/transfer/NS/contacts/DNSSEC/sync)
 * lands with A2/A3 per the full blueprint in docs/domains/README.md §4.1 —
 * extended here as each slice arrives, so the Null adapter never carries dead
 * unimplementable signatures.
 */
interface DomainRegistrar
{
    /** Stable registrar key (e.g. 'openprovider', 'grepp', 'manual'); matches the registry key. */
    public function key(): string;

    /** What this adapter can do — the UI/import adapt on these flags (README §2). */
    public function capabilities(): DomainRegistrarCapabilities;

    /** Smoke-test credentials + reachability (the connection «Έλεγχος σύνδεσης» action). */
    public function ping(DomainRegistrarCredentials $credentials): bool;

    /**
     * Is the name registrable right now? Throws DomainRegistrarNotConfigured on
     * an API-less/unwired registrar — availability feeds a register decision, so
     * it must fail loudly, never guess.
     */
    public function checkAvailability(string $fqdn, DomainRegistrarCredentials $credentials): AvailabilityResult;
}
