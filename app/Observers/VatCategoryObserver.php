<?php

namespace App\Observers;

use App\Models\VatCategory;

/**
 * Replacement for the legacy VAT_CATEGORY_AU0 Firebird trigger (see
 * legacy/ekdosi-schema.sql:1103). When one VAT category becomes the
 * default for a tenant, every other row in the same tenant must drop
 * its default flag — otherwise the form layer ends up picking
 * non-deterministically among multiple is_default=true rows.
 *
 * The legacy trigger only fired AFTER UPDATE; we cover both saving
 * paths (created + updated) so a freshly-created row with
 * is_default=true also demotes the others.
 *
 * Why split events instead of using `saved`:
 *   - `created` fires once and only after INSERT — clean signal.
 *   - `updated` lets us use wasChanged('is_default') to skip the cross-
 *     row UPDATE on no-op saves (e.g. operator edits long_description
 *     of the current default — no need to re-demote N siblings every
 *     time, and once spatie/laravel-activitylog is wired to
 *     vat_categories per the CLAUDE.md plan that would create spurious
 *     change rows for every sibling).
 *   - `saved` would conflate the two and force a `wasRecentlyCreated`
 *     check that doesn't reset on subsequent updates of the same
 *     in-memory model — a footgun (we tripped it during the PR-19
 *     code review).
 *
 * The ETL has a one-shot version of this fix-up at import time (see
 * MigrateFromFirebird::demoteDuplicateVatDefaults) — this observer
 * keeps the invariant going for steady-state UI edits.
 */
class VatCategoryObserver
{
    public function created(VatCategory $vatCategory): void
    {
        if (! $vatCategory->is_default) {
            return;
        }

        $this->demoteOthers($vatCategory);
    }

    public function updated(VatCategory $vatCategory): void
    {
        if (! $vatCategory->is_default) {
            return;
        }

        // Only demote when is_default actually flipped during THIS save —
        // no point re-running the cross-row UPDATE on every cosmetic edit
        // of the existing default.
        if (! $vatCategory->wasChanged('is_default')) {
            return;
        }

        $this->demoteOthers($vatCategory);
    }

    private function demoteOthers(VatCategory $vatCategory): void
    {
        VatCategory::query()
            ->where('company_id', $vatCategory->company_id)
            ->whereKeyNot($vatCategory->getKey())
            ->where('is_default', true)
            ->update(['is_default' => false]);
    }
}
