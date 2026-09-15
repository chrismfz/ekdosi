<?php

namespace App\Services\WhmcsInbox;

/**
 * The plan for resolving a WHMCS mass-pay «container» — what its children are,
 * what merges into one παραστατικό (same billing party), and what stays out
 * (a child billed to a THIRD party keeps its own document). Pure value object,
 * built by MassPayConsolidator::plan(); the two actions (consolidate / explode)
 * act on it.
 *
 * WHMCS zeroes a child invoice's `total` when it rolls into a mass-pay, so the
 * real value lives ONLY in the child's line items (net amount + `taxed`). This
 * plan reconstructs each child's true gross from its items and cross-checks it
 * against the mass-pay's per-child reference amount.
 */
final class MassPayConsolidation
{
    /**
     * @param  array<int, MassPayChild>  $sameParty  children billed to the mass-pay's own customer (→ merge / per-order)
     * @param  array<int, MassPayChild>  $thirdParty  children billed to a DIFFERENT party (→ kept separate)
     * @param  array<int, int>  $missing  child ids WHMCS would not return (unreachable)
     */
    public function __construct(
        public readonly array $sameParty,
        public readonly array $thirdParty,
        public readonly array $missing,
        public readonly float $massPayTotal,
    ) {}

    /** @return array<int, MassPayChild> every child we could fetch (same + third party). */
    public function fetched(): array
    {
        return array_merge($this->sameParty, $this->thirdParty);
    }

    public function hasThirdParty(): bool
    {
        return $this->thirdParty !== [];
    }

    public function isFullyFetched(): bool
    {
        return $this->missing === [];
    }

    /** Net Σ of the same-party children (the consolidated invoice's net). */
    public function samePartyNet(): float
    {
        return round(array_sum(array_map(fn (MassPayChild $c): float => $c->net, $this->sameParty)), 2);
    }

    /** Gross Σ of the same-party children (must reconcile to the payment). */
    public function samePartyGross(): float
    {
        return round(array_sum(array_map(fn (MassPayChild $c): float => $c->gross, $this->sameParty)), 2);
    }
}
