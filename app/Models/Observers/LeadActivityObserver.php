<?php

namespace App\Models\Observers;

use App\Enums\LeadActivityType;
use App\Models\Lead;
use App\Models\LeadActivity;

/**
 * Keeps the `leads.last_activity_at` cache = MAX(happened_at) of the lead's
 * CONTACT rows (call / email / meeting / quote — see LeadActivityType::isContact).
 * Notes and system rows (status changes, conversion) never count, so a
 * back-dated call that flips the status to-day can't make the lead look
 * freshly worked. Written with a query-builder update (no Eloquent events), so
 * it never produces an activity-log row and never re-enters Lead::booted().
 * Mirrors how InvoiceBalance owns the invoice money cache: ONE writer.
 */
class LeadActivityObserver
{
    public function saved(LeadActivity $activity): void
    {
        $this->refresh($activity->lead_id, $activity->company_id);
    }

    public function deleted(LeadActivity $activity): void
    {
        $this->refresh($activity->lead_id, $activity->company_id);
    }

    private function refresh(?int $leadId, ?int $activityCompanyId): void
    {
        if ($leadId === null || $activityCompanyId === null) {
            return;
        }

        // Explicit tenant predicate (CLAUDE.md CLI/observer rule) — lead_id is
        // globally unique, but the intent must be declared, not assumed.
        $latest = LeadActivity::query()
            ->where('company_id', $activityCompanyId)
            ->where('lead_id', $leadId)
            ->whereIn('type', LeadActivityType::contactValues())
            ->max('happened_at');

        // The write is tenant-bounded too: a malformed row (company A, lead of
        // company B) must not be able to touch another tenant's cache.
        Lead::query()
            ->withoutGlobalScopes()
            ->where('company_id', $activityCompanyId)
            ->whereKey($leadId)
            ->update(['last_activity_at' => $latest]);
    }
}
