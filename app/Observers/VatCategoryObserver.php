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
 * The legacy trigger only fired on AFTER UPDATE; we cover both saving
 * paths (create + update) so a freshly-created row with is_default=1
 * also demotes the others. The ETL has a one-shot version of this
 * fix-up at import time (see InvoiceNumberer's sibling helper in
 * MigrateFromFirebird::demoteDuplicateVatDefaults) — this observer
 * keeps the invariant going for steady-state UI edits.
 */
class VatCategoryObserver
{
    public function saved(VatCategory $vatCategory): void
    {
        if (! $vatCategory->is_default) {
            return;
        }

        VatCategory::query()
            ->where('company_id', $vatCategory->company_id)
            ->whereKeyNot($vatCategory->getKey())
            ->where('is_default', true)
            ->update(['is_default' => false]);
    }
}
