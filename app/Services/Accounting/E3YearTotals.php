<?php

namespace App\Services\Accounting;

use App\Models\Company;
use App\Models\E3YearSnapshot;
use App\Services\MyData\E3Report;
use App\Services\MyData\E3Reporter;
use App\Services\MyData\E3ReportRow;
use App\Support\MyData\Codes;
use Carbon\Carbon;
use GuzzleHttp\Handler\MockHandler;

/**
 * Turns AADE's Ε3 aggregation (RequestE3Info — the accountant's FINAL
 * classification) into the three figures the income-tax estimate needs, and
 * stores them as a per-year {@see E3YearSnapshot}.
 *
 *   income  = Σ category1_*   except 1_7 (για λογαριασμό τρίτων) and 1_95 (πληροφοριακά)
 *   capex   = Σ category2_7 + any E3_882/883 line (αγορές παγίων — written off via αποσβέσεις)
 *   expense = Σ category2_*   except capex, 2_9 (για λογαριασμό τρίτων), 2_95 (πληροφοριακά);
 *             2_14 «αποθέματα λήξης» SUBTRACTS (closing stock lowers the cost)
 *
 * Validated against myip 2025 (income 163.813,02 · expense 173.270,72 · capex
 * 100.196,29 → result −9.457,70, the accountant's figure). Inventory (2_13/2_14)
 * and asset SALES (1_4) are passed through as described — confirm with the
 * accountant if a tenant carries stock or sells fixed assets.
 */
class E3YearTotals
{
    private const INCOME_EXCLUDED = ['category1_7', 'category1_95'];

    private const EXPENSE_EXCLUDED = ['category2_9', 'category2_95'];

    /**
     * @param  list<E3ReportRow>  $rows
     * @return array{income: float, expense: float, capex: float}
     */
    public static function fromRows(array $rows): array
    {
        $income = $expense = $capex = 0.0;

        foreach ($rows as $row) {
            $category = (string) $row->classCategory;

            if (str_starts_with($category, 'category1_')) {
                if (! in_array($category, self::INCOME_EXCLUDED, true)) {
                    $income += $row->value;
                }

                continue;
            }

            // An asset purchase is capex whatever its category (even a missing one).
            if ($category === 'category2_7' || Codes::isCapexClassification($row->classType)) {
                if (! in_array($category, self::EXPENSE_EXCLUDED, true)) {
                    $capex += $row->value;
                }

                continue;
            }

            if (! str_starts_with($category, 'category2_') || in_array($category, self::EXPENSE_EXCLUDED, true)) {
                continue;
            }

            $expense += $category === 'category2_14' ? -$row->value : $row->value;
        }

        return ['income' => round($income, 2), 'expense' => round($expense, 2), 'capex' => round($capex, 2)];
    }

    /**
     * Categories whose face value is NOT what the tax sees and that the rule
     * passes through as-is — flagged on the page for the accountant: asset SALES
     * (the whole price counts, only the gain is taxable), prior/next-period items,
     * inventories.
     */
    public const REVIEW_CATEGORIES = [
        'category1_4' => 'Πώληση παγίων (φορολογείται μόνο το κέρδος)',
        'category1_8' => 'Έσοδα προηγούμενων χρήσεων',
        'category1_9' => 'Έσοδα επόμενων χρήσεων',
        'category2_10' => 'Έξοδα προηγούμενων χρήσεων',
        'category2_11' => 'Έξοδα επόμενων χρήσεων',
        'category2_13' => 'Αποθέματα έναρξης',
        'category2_14' => 'Αποθέματα λήξης',
    ];

    /**
     * @param  list<array{type: string, category: ?string, value: float, count: int}>  $storedRows
     * @return array<string, float> label => Σ value, for the REVIEW_CATEGORIES present
     */
    public static function reviewFlags(array $storedRows): array
    {
        $out = [];
        foreach ($storedRows as $r) {
            $label = self::REVIEW_CATEGORIES[$r['category'] ?? ''] ?? null;
            if ($label !== null && abs((float) $r['value']) > 0.004) {
                $out[$label] = round(($out[$label] ?? 0.0) + (float) $r['value'], 2);
            }
        }

        return $out;
    }

    /**
     * Does a snapshot cover the whole calendar year? The window must reach 31/12
     * AND it must have been fetched AFTER the year closed — a fetch at 10:00 on
     * 31/12 (window «to today») is not the year's final Ε3. (Whether the accountant
     * finished the Q4 postings by then is shown by the «ανανέωση» date — refresh.)
     */
    public static function coversFullYear(E3YearSnapshot $s): bool
    {
        return $s->through->toDateString() === sprintf('%04d-12-31', $s->year)
            && $s->fetched_at->year > $s->year;
    }

    /**
     * Fetch the year's Ε3 from AADE (1/1 → 31/12, or → today for the running year)
     * and store it. One read-only AADE call (+ pagination). Throws what E3Reporter
     * throws (RuntimeException for config, RateLimitExceededException, …).
     */
    public static function refresh(Company $tenant, int $year, ?MockHandler $handler = null): E3YearSnapshot
    {
        $from = Carbon::create($year, 1, 1)->startOfDay();
        $to = Carbon::create($year, 12, 31)->endOfDay();
        if ($to->isFuture()) {
            $to = now()->endOfDay();
        }

        $report = (new E3Reporter($tenant, $handler))->report($from, $to);
        $totals = self::fromRows($report->rows);

        return E3YearSnapshot::query()->updateOrCreate(
            ['company_id' => $tenant->getKey(), 'year' => $year],
            $totals + [
                'doc_count' => $report->docCount,
                'rows' => self::serializeRows($report),
                'through' => $to->toDateString(),
                'fetched_at' => now(),
            ],
        );
    }

    /** @return list<array{type: string, category: ?string, value: float, count: int}> */
    private static function serializeRows(E3Report $report): array
    {
        return array_map(fn (E3ReportRow $r) => [
            'type' => $r->classType, 'category' => $r->classCategory, 'value' => $r->value, 'count' => $r->count,
        ], $report->rows);
    }
}
