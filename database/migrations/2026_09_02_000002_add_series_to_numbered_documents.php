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
        [$markTable, $foreignKey] = $table === 'invoices'
            ? ['mydata_marks', 'invoice_id']
            : ['delivery_marks', 'delivery_note_id'];

        DB::table($table)
            ->select('id', 'invcode', 'code')
            ->whereNull('series')
            ->whereNotNull('invcode')
            // chunkById, NOT chunk(): this loop writes the very column the
            // whereNull filters on, so paging by OFFSET would skip rows.
            ->chunkById(500, function ($rows) use ($table, $markTable, $foreignKey): void {
                $filed = $this->filedSeriesFromMarks($markTable, $foreignKey, $rows->pluck('id')->all());

                // One UPDATE per distinct series rather than one per row.
                $bySeries = [];
                foreach ($rows as $row) {
                    // What we ACTUALLY sent wins over what invcode implies. They
                    // differ only when the type was renamed between numbering and
                    // filing — and in exactly that case freezing the invcode value
                    // would turn a row the reconciler currently matches into a
                    // permanent conflict.
                    $series = $filed[$row->id]
                        ?? DocumentSeries::fromInvcode($row->invcode, $row->code);

                    if ($series !== null) {
                        $bySeries[$series][] = $row->id;
                    }
                }

                foreach ($bySeries as $series => $ids) {
                    DB::table($table)->whereIn('id', $ids)->update(['series' => $series]);
                }
            });
    }

    /**
     * Series parsed out of the stored request XML of each document's FIRST issue
     * filing, keyed by document id.
     *
     * Only INSERT rows that carry a real MARK: a CANCEL row's `request` is a
     * free-text reason rather than XML, and a dry-run or rejected attempt was
     * never accepted by AADE, so neither says what the document is filed as.
     * Oldest-first so a re-file (a second INSERT) cannot overwrite the identity
     * the document has held since its first accepted filing.
     *
     * @param  array<int, int>  $ids
     * @return array<int, string>
     */
    private function filedSeriesFromMarks(string $markTable, string $foreignKey, array $ids): array
    {
        if ($ids === [] || ! Schema::hasTable($markTable)) {
            return [];
        }

        $series = [];

        DB::table($markTable)
            ->select('id', $foreignKey, 'request')
            ->whereIn($foreignKey, $ids)
            ->whereNotNull('mark')
            ->where('mark', '!=', '')
            ->where('mydata_action', 'INSERT')
            ->whereNotNull('request')
            ->orderBy('id')
            ->each(function ($mark) use (&$series, $foreignKey): void {
                $id = $mark->{$foreignKey};

                if (isset($series[$id])) {
                    return; // first accepted filing wins
                }

                $parsed = DocumentSeries::fromRequestXml($mark->request);

                if ($parsed !== null) {
                    $series[$id] = $parsed;
                }
            });

        return $series;
    }
};
