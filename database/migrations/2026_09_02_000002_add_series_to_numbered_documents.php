<?php

use App\Support\DocumentSeries;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * MYD-018 / MYD-024: freeze the FILED series on every numbered document.
 *
 * A παραστατικό is identified to AADE by (series, ΑΑ). The ΑΑ was always frozen
 * (`code`), but the series was read live from `invoice_types.code` — an editable
 * lookup. Renaming a series therefore rewrote what an already-numbered document
 * claimed to be, and made the in-doubt recovery search AADE for the WRONG
 * (series, ΑΑ): not finding the MARK that already exists, it would file the
 * document a SECOND time.
 *
 * The backfill does not have to guess. `invcode` is itself frozen and is exactly
 * `series . code`, so the historical series is recovered from it — the value as
 * it was at issue, not today's lookup. Rows whose pair doesn't have that shape
 * are left null and fall back to the live type code at read time, i.e. exactly
 * the behaviour they have today.
 */
return new class extends Migration
{
    public function up(): void
    {
        // hasColumn-guarded so a re-run after a backfill that died mid-way
        // (a big tenant, a killed process) resumes instead of erroring on the
        // column — the backfill itself only ever touches still-null rows.
        foreach (['invoices', 'delivery_notes'] as $table) {
            if (! Schema::hasColumn($table, 'series')) {
                Schema::table($table, function (Blueprint $t): void {
                    $t->string('series', 20)->nullable()->after('invcode');
                });
            }

            $this->backfill($table);
        }
    }

    public function down(): void
    {
        Schema::table('invoices', function (Blueprint $table): void {
            $table->dropColumn('series');
        });

        Schema::table('delivery_notes', function (Blueprint $table): void {
            $table->dropColumn('series');
        });
    }

    private function backfill(string $table): void
    {
        DB::table($table)
            ->select('id', 'invcode', 'code')
            ->whereNull('series')
            ->whereNotNull('invcode')
            // chunkById, NOT chunk(): this loop writes the very column the
            // whereNull filters on, so paging by OFFSET would skip rows.
            ->chunkById(500, function ($rows) use ($table): void {
                // One UPDATE per distinct series rather than one per row.
                $bySeries = [];
                foreach ($rows as $row) {
                    $series = DocumentSeries::fromInvcode($row->invcode, $row->code);
                    if ($series !== null) {
                        $bySeries[$series][] = $row->id;
                    }
                }

                foreach ($bySeries as $series => $ids) {
                    DB::table($table)->whereIn('id', $ids)->update(['series' => $series]);
                }
            });
    }
};
