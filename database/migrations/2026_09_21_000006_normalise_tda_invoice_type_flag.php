<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Combined ΤΔΑ — Slice 3d legacy normaliser (`docs/combined-tda-design.md` §8).
 *
 * A `ΤΔΑ`-coded invoice_type that PREDATES the `is_delivery_note` flag — an ETL'd
 * row (`MigrateFromFirebird::copyInvoiceTypes` never set it) or a seeder run before
 * 3d re-added the ΤΔΑ seed row — carries `is_delivery_note=false`. Picking such a
 * type would file the invoice as a PLAIN 1.1 with no movement header (the exact
 * MYD-002 hazard the 3a/3b seed-withholding guarded against). This flips it at
 * deploy time so the flag is right regardless of whether the seeder is re-run.
 *
 * Deliberately TARGETED + fill-empty, never clobbering an operator's real choice:
 *   - only `code = 'ΤΔΑ'` (the seeded ΤΔΑ series code);
 *   - only when `is_delivery_note` is still false (idempotent — a re-run finds none);
 *   - only when `mydata_type` is blank or already '1.1' (if an operator reclassified
 *     a 'ΤΔΑ'-coded series to a DIFFERENT §8.1 type, leave it — its type is intent);
 *   - sets `mydata_type='1.1'` only when blank (never overwrites a set value).
 * Every changed row is logged with before/after so the fix is auditable.
 *
 * Irreversible by design — down() is a no-op: un-flipping would only re-introduce
 * the mis-filing hazard on the exact rows this repaired.
 */
return new class extends Migration
{
    public function up(): void
    {
        $rows = DB::table('invoice_types')
            ->select('id', 'company_id', 'code', 'mydata_type', 'is_delivery_note')
            ->where('code', 'ΤΔΑ')
            ->where('is_delivery_note', false)
            ->get();

        foreach ($rows as $row) {
            $type = (string) ($row->mydata_type ?? '');

            // Skip a 'ΤΔΑ'-coded series an operator reclassified to another type —
            // its §8.1 type is a deliberate choice, not ours to override.
            if ($type !== '' && $type !== '1.1') {
                continue;
            }

            $update = ['is_delivery_note' => true];
            if ($type === '') {
                $update['mydata_type'] = '1.1';
            }

            DB::table('invoice_types')->where('id', $row->id)->update($update);

            Log::info('Normalised ΤΔΑ invoice_type flag', [
                'invoice_type_id' => $row->id,
                'company_id' => $row->company_id,
                'code' => $row->code,
                'mydata_type_before' => $row->mydata_type,
                'mydata_type_after' => $update['mydata_type'] ?? $row->mydata_type,
                'is_delivery_note' => true,
            ]);
        }
    }

    public function down(): void
    {
        // No-op: un-flipping would re-introduce the plain-1.1 mis-filing hazard.
    }
};
