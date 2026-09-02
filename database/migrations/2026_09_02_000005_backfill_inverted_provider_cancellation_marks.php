<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * MYD-023 backfill: un-invert the provider CANCEL rows written by the old code.
 *
 * Both provider cancel paths used to persist
 * `$result->cancellationMark ?? $markToCancel` into `mark`. So a historical row
 * holds EITHER the cancellation MARK (when the provider returned one) OR the
 * issue MARK (when it did not) — and nothing says which. Now that `mark` means
 * «the document cancelled» and `cancellation_mark` means «the cancellation act»,
 * a row of the first kind reads as the exact inverse of the truth, on the PDF
 * audit table and in the panel alike.
 *
 * The two kinds ARE separable, without guessing: the issue MARK is recorded
 * independently on the document's own INSERT/PROVIDER_INSERT row, which is where
 * `cancel()` reads `$markToCancel` from in the first place. So:
 *
 *   - CANCEL row's mark == the sibling INSERT mark → it was the fallback. Already
 *     correct under the new meaning; leave it, and leave `cancellation_mark` null
 *     (there genuinely is no evidence to record).
 *   - CANCEL row's mark != the sibling INSERT mark → it is the cancellation MARK.
 *     Move it to `cancellation_mark` and put the issue MARK in `mark`.
 *   - No sibling INSERT row → we cannot prove which kind it is. LEAVE IT ALONE.
 *
 * Nothing is discarded: every value is preserved, only relocated to the column
 * that now claims it. Rows already carrying a `cancellation_mark` (written by
 * the new code) are skipped, so this is idempotent and safe to re-run.
 *
 * Expected to touch ZERO rows on every current deployment — no tenant has filed
 * through a ΥΠΑΗΕΣ provider in production yet. It exists so that a host which
 * HAS is not silently left with inverted legal evidence.
 */
return new class extends Migration
{
    public function up(): void
    {
        $this->backfill(
            table: 'mydata_marks',
            documentKey: 'invoice_id',
            cancelActions: ['PROVIDER_CANCEL'],
            issueActions: ['PROVIDER_INSERT'],
        );

        // Delivery notes: only the PROVIDER path could invert them — the direct
        // path always wrote the issue MARK, which is already what `mark` means.
        // Both write action CANCEL, so `provider_key` is what separates them.
        $this->backfill(
            table: 'delivery_marks',
            documentKey: 'delivery_note_id',
            cancelActions: ['CANCEL'],
            issueActions: ['PROVIDER_INSERT'],
            providerOnly: true,
        );
    }

    public function down(): void
    {
        // Deliberately irreversible: the pre-migration state cannot be
        // reconstructed (which rows were inverted is exactly what was ambiguous),
        // and re-inverting correct evidence would be the harm this fixes. The
        // companion migration's down() drops the column outright anyway.
    }

    /**
     * @param  list<string>  $cancelActions
     * @param  list<string>  $issueActions
     */
    private function backfill(
        string $table,
        string $documentKey,
        array $cancelActions,
        array $issueActions,
        bool $providerOnly = false,
    ): void {
        if (! Schema::hasTable($table) || ! Schema::hasColumn($table, 'cancellation_mark')) {
            return;
        }

        $candidates = DB::table($table)
            ->whereIn('mydata_action', $cancelActions)
            ->whereNull('cancellation_mark')
            ->whereNotNull('mark')
            ->where('mark', '!=', '')
            ->whereNotNull($documentKey)
            ->when($providerOnly, fn ($q) => $q->whereNotNull('provider_key'))
            ->get(['id', $documentKey, 'mark']);

        foreach ($candidates as $row) {
            $issueMark = DB::table($table)
                ->where($documentKey, $row->{$documentKey})
                ->whereIn('mydata_action', $issueActions)
                ->whereNotNull('mark')
                ->where('mark', '!=', '')
                ->orderByDesc('id')
                ->value('mark');

            // No sibling issue row, or the row already means what it says.
            if ($issueMark === null || (string) $issueMark === (string) $row->mark) {
                continue;
            }

            DB::table($table)->where('id', $row->id)->update([
                'mark' => $issueMark,
                'cancellation_mark' => $row->mark,
            ]);
        }
    }
};
