<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Data fix: trim `companies.einvoice_provider_key` (whitespace-only → NULL).
 *
 * `Company::einvoiceProviderKey()` now normalises on write, but rows written
 * BEFORE it (an import, a seed, a hand-edited row) keep their stray whitespace —
 * and a stale row is exactly where the damage shows: the Company form becomes
 * unsaveable (the composed channel isn't in the dropdown's options), the invoice
 * payload preview shows un-augmented XML while the real filing is augmented, the
 * dashboard quota card reads «καμία υποβολή ακόμη», and the go-live gate passes a
 * tenant that resolves to the Null transport.
 *
 * Deliberately NOT destructive: only leading/trailing whitespace is removed, so a
 * key that was already clean is untouched and a key that was whitespace-only was
 * never a usable provider reference in the first place. Every changed row is
 * logged with its before/after so the fix is auditable after the fact.
 *
 * Irreversible by design — down() is a no-op, because restoring whitespace would
 * only re-break the rows this repaired.
 */
return new class extends Migration
{
    public function up(): void
    {
        $rows = DB::table('companies')
            ->select('id', 'slug', 'einvoice_provider_key')
            ->whereNotNull('einvoice_provider_key')
            ->get();

        foreach ($rows as $row) {
            $raw = (string) $row->einvoice_provider_key;
            $clean = trim($raw);
            if ($clean === $raw) {
                continue;
            }

            DB::table('companies')
                ->where('id', $row->id)
                ->update(['einvoice_provider_key' => $clean === '' ? null : $clean]);

            Log::info('Normalised companies.einvoice_provider_key', [
                'company_id' => $row->id,
                'slug' => $row->slug,
                'before' => $raw,
                'after' => $clean === '' ? null : $clean,
            ]);
        }
    }

    public function down(): void
    {
        // No-op: re-introducing the whitespace would restore the bug.
    }
};
