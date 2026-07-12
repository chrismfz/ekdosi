<?php

namespace App\Services\MyData;

use App\Models\Company;
use App\Models\Supplier;

/**
 * Fill missing names on suppliers auto-created from myDATA with only their ΑΦΜ.
 *
 * GR myDATA documents forbid the domestic party name ([219]/[220]), so a
 * supplier discovered from an expense doc lands as a bare ΑΦΜ («παύλα» στη λίστα
 * Έξοδα). New imports now GSIS-enrich on create; this repairs the ones created
 * BEFORE that (or where GSIS was down at import time). Fill-only-empty — an
 * operator-typed name/address is never overwritten.
 *
 * Shared by the `suppliers:backfill-names` CLI (unbounded sweep) and the
 * Προμηθευτές list button (bounded batch, so a web request never fans out into
 * dozens of synchronous SOAP calls and times out). Tenant-scoped explicitly —
 * Supplier's global scope is a no-op on the CLI. Best-effort: never throws.
 */
class SupplierNameBackfiller
{
    public function __construct(private readonly Company $tenant) {}

    /**
     * Enrich up to $limit nameless GR suppliers (0 = no limit).
     */
    public function run(int $limit = 0): SupplierBackfillResult
    {
        // Nameless GR suppliers with an ΑΦΜ. withTrashed is skipped: a
        // soft-deleted supplier was removed on purpose, don't resurrect its name.
        $query = Supplier::query()
            ->where('company_id', $this->tenant->getKey())
            ->where(fn ($q) => $q->where('country', 'GR')->orWhereNull('country'))
            ->whereNotNull('afm')
            ->where('afm', '!=', '')
            ->where(fn ($q) => $q->whereNull('name')->orWhere('name', ''))
            ->orderBy('id');

        if ($limit > 0) {
            $query->limit($limit);
        }

        $suppliers = $query->get();

        $enricher = new SupplierGsisEnricher($this->tenant);
        $enriched = 0;
        $failures = [];

        foreach ($suppliers as $supplier) {
            $gsis = $enricher->enrich((string) $supplier->afm);
            if ($gsis === null) {
                $failures[] = (string) $supplier->afm;

                continue;
            }

            // Fill only-empty columns — never clobber an operator's manual edit.
            $changed = false;
            foreach ($gsis as $column => $value) {
                if (blank($supplier->{$column})) {
                    $supplier->{$column} = $value;
                    $changed = true;
                }
            }

            if ($changed) {
                $supplier->save();
                $enriched++;
            }
        }

        return new SupplierBackfillResult(
            processed: $suppliers->count(),
            enriched: $enriched,
            failures: $failures,
            // Processed exactly $limit rows → more nameless ones likely remain.
            hitLimit: $limit > 0 && $suppliers->count() === $limit,
        );
    }
}
