<?php

namespace App\Filament;

use Filament\Resources\Pages\ListRecords;
use Filament\Support\Enums\Width;

/**
 * Shared base for every resource list page.
 *
 * Filament caps page content at Width::SevenExtraLarge (1280px) by
 * default. On wide monitors (e.g. 2560px) that left a large empty
 * margin to the right while data tables still showed a horizontal
 * scrollbar. List/table pages extend this base so the table can use the
 * full available width instead.
 *
 * Scope is deliberately list pages only: create/edit FORM pages keep
 * the default ~1280px reading width (full-width form inputs on a 30"
 * screen read poorly). Smaller screens are unaffected — the 1280px cap
 * was never binding below it, and the table keeps its own horizontal
 * overflow for the rare case it's still needed.
 */
abstract class BaseListRecords extends ListRecords
{
    public function getMaxContentWidth(): Width | string | null
    {
        return Width::Full;
    }

    /**
     * Tolerate a request to clear a table filter that no longer exists on the
     * table. Filament's `tableFilters` state is URL-bound (`#[Url(as: 'filters')]`)
     * and also rides the Livewire snapshot, so a bookmarked `?filters=…` URL — or
     * a browser tab left open across a deploy — can still carry a filter key that a
     * later release removed (e.g. the WHMCS inbox `status` SelectFilter, replaced by
     * status tabs). When the frontend then asks to remove it, the parent runs
     * `->getResetState()` on the null filter and 500s (HasFilters::removeTableFilter,
     * line 81). Drop the orphaned key instead of crashing; a filter that still exists
     * goes through the normal parent path unchanged.
     */
    public function removeTableFilter(string $filterName, ?string $field = null, bool $isRemovingAllFilters = false): void
    {
        if ($this->getTable()->getFilter($filterName) !== null) {
            parent::removeTableFilter($filterName, $field, $isRemovingAllFilters);

            return;
        }

        // Orphaned key: drop it from the live state. Livewire re-syncs the URL-bound
        // property, and handleTableFilterUpdates() rewrites a session-persisted copy
        // too (the parent runs it as its tail; it's skipped when removing all
        // filters). We deliberately do NOT call applyTableFilters() on the deferred
        // path — it would overwrite tableFilters from tableDeferredFilters and could
        // re-introduce the very key we just removed.
        if (is_array($this->tableFilters)) {
            unset($this->tableFilters[$filterName]);
        }

        if (! $isRemovingAllFilters) {
            $this->handleTableFilterUpdates();
        }
    }
}
