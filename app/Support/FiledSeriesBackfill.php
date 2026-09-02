<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Freeze each numbered document's series from what it was ACTUALLY FILED as
 * (MYD-018).
 *
 * `invcode` is a good source — it is frozen and is exactly `series . code` — but
 * it is not the authoritative one. The two disagree in a real case: a draft
 * numbered under «ΤΠΥ», the type renamed to «ΤΠΥ2», and only then filed. AADE
 * holds ΤΠΥ2 while `invcode` still says ΤΠΥ, so freezing the invcode value there
 * would turn a row the reconciler currently MATCHES into a permanent conflict.
 * The request XML stored on the document's issue MARK is literally what we sent,
 * so it wins wherever it exists.
 *
 * ONE definition, used by BOTH the `series` migration and the Firebird ETL. They
 * cannot share the migration's private method — the ETL must run this AFTER
 * copyMarks(), since the legacy MARK rows (and their REQUEST XML) are not local
 * until then — and a second copy of this rule is exactly how the two would drift
 * apart.
 */
final class FiledSeriesBackfill
{
    /**
     * Apply the filed series to every document in $documentTable whose current
     * value disagrees with it.
     *
     * @param  string  $documentTable  `invoices` or `delivery_notes`
     * @param  int|null  $companyId  restrict to one tenant (the ETL); null = all (the migration)
     * @return int how many rows were corrected
     */
    public static function apply(string $documentTable, ?int $companyId = null): int
    {
        [$markTable, $foreignKey] = $documentTable === 'invoices'
            ? ['mydata_marks', 'invoice_id']
            : ['delivery_marks', 'delivery_note_id'];

        if (! Schema::hasTable($markTable) || ! Schema::hasColumn($documentTable, 'series')) {
            return 0;
        }

        /** @var array<string, list<int>> $bySeries */
        $bySeries = [];
        /** @var array<int, true> $claimed */
        $claimed = [];

        $query = DB::table($markTable)
            ->join($documentTable, "{$documentTable}.id", '=', "{$markTable}.{$foreignKey}")
            // Only rows that PROVE a filing: a CANCEL row's `request` is a free-text
            // reason rather than XML, and a dry-run or rejected attempt was never
            // accepted by AADE, so neither says what the document is filed as.
            // PROVIDER_INSERT is an issue filing too — it carries a real MARK and the
            // real request XML, and every other consumer in the tree pairs the two.
            ->whereNotNull("{$markTable}.mark")
            ->where("{$markTable}.mark", '!=', '')
            ->whereIn("{$markTable}.mydata_action", ['INSERT', 'PROVIDER_INSERT'])
            ->whereNotNull("{$markTable}.request")
            // Oldest first: the FIRST accepted filing owns the identity, so a
            // re-file cannot rewrite what the document has been since.
            ->orderBy("{$markTable}.id")
            ->select("{$markTable}.{$foreignKey} as document_id", "{$markTable}.request", "{$documentTable}.series");

        if ($companyId !== null) {
            $query->where("{$markTable}.company_id", $companyId);
        }

        $query->each(function ($row) use (&$bySeries, &$claimed): void {
            $documentId = (int) $row->document_id;

            if (isset($claimed[$documentId])) {
                return;
            }

            $filed = DocumentSeries::fromRequestXml($row->request);

            if ($filed === null) {
                return; // unreadable → leave whatever is already frozen
            }

            // Claim it either way: an unchanged value is still this document's
            // first accepted filing, and a later one must not override it.
            $claimed[$documentId] = true;

            if ($filed !== $row->series) {
                $bySeries[$filed][] = $documentId;
            }
        });

        $corrected = 0;

        foreach ($bySeries as $series => $ids) {
            // One UPDATE per distinct series rather than one per row.
            DB::table($documentTable)->whereIn('id', $ids)->update(['series' => $series]);
            $corrected += count($ids);
        }

        return $corrected;
    }
}
