<?php

namespace App\Services\Assistant\Tools;

use App\Models\Company;
use App\Models\E3YearSnapshot;
use App\Services\Accounting\E3YearTotals;
use App\Services\Accounting\MonthlyResult;
use App\Support\MyData\Codes;

/**
 * The stored AADE Ε3 snapshot of a year (RequestE3Info, refreshed nightly by
 * mydata:sync-expenses / the page's «Ανανέωση Ε3») — the accountant's own
 * classification, INCLUDING entries filed under the accountant's credentials that
 * never reach us as documents. Totals, the column groups «Φορολογικά» uses
 * ({@see E3YearTotals::groups}) per year and per month, and — with `detail` — the
 * raw (type, category, value) rows per month. Stored data only; no AADE call.
 */
class E3SnapshotTool implements AssistantTool
{
    use ReadsTaxYear;

    public function name(): string
    {
        return 'e3_snapshot';
    }

    public function description(): string
    {
        return 'Το αποθηκευμένο Ε3 της ΑΑΔΕ για ένα έτος (ο χαρακτηρισμός του λογιστή — μετράει και ό,τι περνά με '
            .'δικούς του κωδικούς): σύνολα έσοδα/έξοδα/αγορές παγίων/αποτέλεσμα, ανάλυση σε μισθοδοσία, εισφορές '
            .'(E3_581_002 εργοδοτικές, E3_585_007 ΕΦΚΑ αυτοαπασχολούμενων), αποσβέσεις, λοιπά — ανά έτος ΚΑΙ ανά μήνα, '
            .'με ημερομηνία ανανέωσης. Με `detail`=true και οι γραμμές (τύπος E3_xxx, κατηγορία, ποσό) ανά μήνα. Για '
            .'«τι έχει περάσει ο λογιστής», «πόση μισθοδοσία/εισφορές δείχνει το Ε3», «σύγκρινε με τα τοπικά». '
            .'Αποθηκευμένα δεδομένα, χωρίς κλήση ΑΑΔΕ.';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'year' => ['type' => 'integer', 'description' => 'Ημερολογιακό έτος. Προεπιλογή: το τρέχον.'],
                'detail' => ['type' => 'boolean', 'description' => 'Και οι γραμμές (τύπος/κατηγορία/ποσό) ανά μήνα. Προεπιλογή false.'],
            ],
        ];
    }

    public function permission(): ?string
    {
        return 'View:TaxOverview';
    }

    public function run(Company $tenant, array $input): array
    {
        if ($error = $this->rejectNonGreek($tenant)) {
            return $error;
        }
        $year = $this->year($input);
        if (is_array($year)) {
            return $year;
        }

        $snap = E3YearSnapshot::query()->where('company_id', $tenant->getKey())->where('year', $year)->first();
        if ($snap === null) {
            return [
                'year' => $year,
                'found' => false,
                'hint' => 'Δεν υπάρχει αποθηκευμένο Ε3 για το έτος. Ανανεώνεται κάθε νύχτα από το mydata:sync-expenses '
                    .'(τρέχον έτος + το προηγούμενο έως Ιούλιο) ή με «Ανανέωση Ε3 (ΑΑΔΕ)» στα Φορολογικά.',
            ];
        }

        $detail = (bool) ($input['detail'] ?? false);
        $months = [];
        foreach ((array) ($snap->monthly ?? []) as $month => $rows) {
            $m = (int) $month;
            $entry = ['month' => $m, 'label' => MonthlyResult::MONTH_LABELS[$m] ?? (string) $m] + E3YearTotals::groups($rows);
            if ($detail) {
                $entry['rows'] = array_map(fn (array $r) => [
                    'type' => $r['type'],
                    'type_label' => Codes::e3TypeLabel((string) $r['type']),
                    'category' => $r['category'],
                    'value' => $r['value'],
                ], $rows);
            }
            $months[] = $entry;
        }
        usort($months, fn (array $a, array $b) => $a['month'] <=> $b['month']);

        return [
            'year' => $year,
            'found' => true,
            'currency' => 'EUR',
            'through' => $snap->through->toDateString(),
            'fetched_at' => $snap->fetched_at->toDateTimeString(),
            'full_year' => E3YearTotals::coversFullYear($snap),
            'entries' => $snap->doc_count,
            'totals' => [
                'income' => (float) $snap->income,
                'expense' => (float) $snap->expense,
                'capex' => (float) $snap->capex,
                'result' => round((float) $snap->income - (float) $snap->expense, 2),
            ],
            'groups' => E3YearTotals::groups($snap->rows ?? []),
            'review_flags' => E3YearTotals::reviewFlags($snap->rows ?? []),
            'monthly_available' => $snap->monthly !== null,
            'months' => $months,
        ];
    }
}
