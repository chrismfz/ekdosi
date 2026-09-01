<?php

namespace App\Models\Observers;

use App\Models\Lead;
use App\Models\LeadActivity;

/**
 * Keeps the `leads.last_activity_at` cache = MAX(happened_at) of the lead's
 * timeline rows. Written with a query-builder update (no Eloquent events), so
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
            ->max('happened_at');

        Lead::query()
            ->withoutGlobalScopes()
            ->whereKey($leadId)
            ->update(['last_activity_at' => $latest]);
    }
}
