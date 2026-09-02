<?php

use App\Support\DocumentSeries;
use App\Support\FiledSeriesBackfill;
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
 * The backfill does not have to guess. For a document we have already FILED, the
 * request XML stored on its issue MARK is authoritative — it is literally what we
 * sent AADE. Everything else falls back to `invcode`, which is itself frozen and
 * is exactly `series . code`, so the historical series is recovered from it.
 *
 * The two disagree in one real case, which is why the MARK is consulted first: a
 * draft numbered under «ΤΠΥ», the type renamed to «ΤΠΥ2», and only then filed.
 * AADE holds ΤΠΥ2 while `invcode` still says ΤΠΥ; freezing the invcode value there
 * would turn a row the reconciler currently MATCHES into a permanent conflict —
 * this fix causing the very problem it exists to prevent.
 *
 * Rows that have neither an accepted filing nor a readable `invcode` pair are left
 * null and fall back to the live type code at read time, i.e. exactly the behaviour
 * they have today.
 */
return new class extends Migration
{
    public function up(): void
    {
        // hasColumn-guarded so a re-run after a backfill that died mid-way
        // (a big tenant, a killed process) resumes instead of erroring on the
        // column. It converges: pass 1 only fills still-null rows, and pass 2 —
        // deliberately NOT gated on null — re-checks every filed row against its
        // MARK XML, so a row pass 1 had already written before the crash still
        // gets its authoritative value on the re-run.
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

        // Then correct anything the FILED request XML says otherwise — the
        // authoritative source, shared with the ETL so the two cannot drift.
        FiledSeriesBackfill::apply($table);
    }
};
