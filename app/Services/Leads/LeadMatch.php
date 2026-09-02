<?php

namespace App\Services\Leads;

use App\Models\Customer;
use App\Models\Lead;
use App\Support\Afm;
use Illuminate\Support\Collection;

/**
 * Result of LeadMatcher::find(): who else already has this ΑΦΜ / email /
 * phone — existing customers and other leads (including lost / «μην
 * ξαναενοχλήσετε» / soft-deleted ones, which is the whole point).
 */
final class LeadMatch
{
    /**
     * @param  Collection<int, Customer>  $customers  a bounded preview (LeadMatcher::PREVIEW_LIMIT)
     * @param  Collection<int, Lead>  $leads  a bounded preview (LeadMatcher::PREVIEW_LIMIT)
     * @param  bool  $doNotContact  UNBOUNDED: any matching lead is «μην ξαναενοχλήσετε», previewed or not
     */
    public function __construct(
        public readonly Collection $customers,
        public readonly Collection $leads,
        public readonly bool $doNotContact = false,
        /** Non-trashed customers matched on THEIR OWN afm/email/phone (not via a contact) — safe to act on. */
        public readonly Collection $directCustomers = new Collection,
    ) {}

    public static function none(): self
    {
        return new self(collect(), collect(), false, collect());
    }

    /**
     * Live customers (direct hits) whose ΑΦΜ equals the given one — the legal
     * owner(s) of the identity. Empty for a lead without a usable ΑΦΜ.
     *
     * @return Collection<int, Customer>
     */
    public function customersOwningAfm(?string $afm): Collection
    {
        $afm = Afm::normalise($afm);
        if ($afm === null) {
            return collect();
        }

        return $this->directCustomers->filter(fn (Customer $c): bool => Afm::normalise($c->afm) === $afm)->values();
    }

    public function isEmpty(): bool
    {
        return $this->customers->isEmpty() && $this->leads->isEmpty();
    }

    /**
     * Someone already asked us not to contact them. Comes from a dedicated
     * unbounded EXISTS — never from the previewed rows, so a DNC lead can't
     * hide behind newer duplicates.
     */
    public function hasDoNotContact(): bool
    {
        return $this->doNotContact;
    }
}
