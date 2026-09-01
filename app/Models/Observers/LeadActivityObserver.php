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
        $this->refresh($activity->lead_id);
    }

    public function deleted(LeadActivity $activity): void
    {
        $this->refresh($activity->lead_id);
    }

    private function refresh(?int $leadId): void
    {
        if ($leadId === null) {
            return;
        }

        $latest = LeadActivity::query()
            ->where('lead_id', $leadId)
            ->max('happened_at');

        Lead::query()
            ->withoutGlobalScopes()
            ->whereKey($leadId)
            ->update(['last_activity_at' => $latest]);
    }
}
