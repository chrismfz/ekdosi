<?php

namespace App\Services\Domains;

use App\Contracts\DomainRegistrar;
use App\Support\Domains\AvailabilityResult;
use App\Support\Domains\DomainRegistrarCapabilities;
use App\Support\Domains\DomainRegistrarCredentials;

/**
 * The 'manual' registrar (Πυλώνας A) — a first-class API-less adapter, not just
 * a fallback: domains handled entirely by hand (registered elsewhere, tracked
 * here) attach to a 'manual' connection and every API-shaped operation refuses
 * loudly. Also what DomainRegistrarRegistry returns for unknown/empty keys, so
 * a misconfigured connection can never fake a registrar success (the
 * NullProviderTransport discipline). docs/domains/README.md §4.2.
 */
class NullDomainRegistrar implements DomainRegistrar
{
    public function key(): string
    {
        return 'manual';
    }

    public function capabilities(): DomainRegistrarCapabilities
    {
        return DomainRegistrarCapabilities::none();
    }

    public function ping(DomainRegistrarCredentials $credentials): bool
    {
        return false;
    }

    public function checkAvailability(string $fqdn, DomainRegistrarCredentials $credentials): AvailabilityResult
    {
        throw new DomainRegistrarNotConfigured(
            'Ο registrar «manual» δεν έχει API — έλεγχος διαθεσιμότητας δεν υποστηρίζεται σε αυτή τη σύνδεση.'
        );
    }
}
