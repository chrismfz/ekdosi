<?php

namespace App\Services\Leads;

use App\Enums\LeadStatus;
use App\Models\Customer;
use App\Models\Lead;
use Illuminate\Support\Collection;

/**
 * Result of LeadMatcher::find(): who else already has this ΑΦΜ / email /
 * phone — existing customers and other leads (including lost / «μην
 * ξαναενοχλήσετε» / soft-deleted ones, which is the whole point).
 */
final class LeadMatch
{
    /**
     * @param  Collection<int, Customer>  $customers
     * @param  Collection<int, Lead>  $leads
     */
    public function __construct(
        public readonly Collection $customers,
        public readonly Collection $leads,
    ) {}

    public static function none(): self
    {
        return new self(collect(), collect());
    }

    public function isEmpty(): bool
    {
        return $this->customers->isEmpty() && $this->leads->isEmpty();
    }

    /** Someone already asked us not to contact them. */
    public function hasDoNotContact(): bool
    {
        return $this->leads->contains(fn (Lead $l): bool => $l->status === LeadStatus::DoNotContact);
    }
}
