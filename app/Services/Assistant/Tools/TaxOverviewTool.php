<?php

namespace App\Services\Assistant\Tools;

use App\Models\Company;
use App\Services\Accounting\IncomeTaxEstimate;
use App\Services\Accounting\MonthlyResult;
use App\Services\Dashboard\VatPeriodSummary;
use App\Services\Dashboard\VatYearOverview;

/**
 * «Φορολογικά» as a tool — exactly what the page shows, from the same services:
 * the income-tax estimate ({@see IncomeTaxEstimate}: φόρος / προκαταβολή /
 * υπόλοιπο, running-year projection), the month → quarter → year έσοδα/έξοδα table
 * with the source of every cell ({@see MonthlyResult}: local book vs AADE Ε3), and
 * ΦΠΑ per quarter with the πιστωτικό carried forward ({@see VatYearOverview}).
 * Local data + the stored Ε3 snapshot; no AADE call. Greek tenants only.
 */
class TaxOverviewTool implements AssistantTool
{
    use ReadsTaxYear;

    public function name(): string
    {
        return 'tax_overview';
    }

    public function description(): string
    {
        return 'Η σελίδα «Φορολογικά» για ένα έτος, ακριβώς όπως τη βλέπει ο χρήστης: εκτίμηση φόρου εισοδήματος '
            .'(κέρδος, φόρος, παρακρατήσεις, προκαταβολή επόμενου έτους, περσινή βεβαιωμένη προκαταβολή, υπόλοιπο· '
            .'για το τρέχον έτος και η προβολή στις 31/12 + μηνιαία αποταμίευση), έσοδα/έξοδα ανά μήνα → τρίμηνο → '
            .'έτος σε στήλες (μισθοδοσία, εισφορές, αποσβέσεις, λοιπά, αγορές παγίων) με την ΠΗΓΗ κάθε κελιού '
            .'(`e3_columns` = όσες στήλες ήρθαν από το Ε3 ΑΑΔΕ, οι υπόλοιπες τοπικά), και ΦΠΑ ανά τρίμηνο με μεταφερόμενο πιστωτικό. Για «πόσο φόρο θα '
            .'πληρώσουμε», «τι δείχνουν τα Φορολογικά», «γιατί βγαίνει τόσο ο φόρος/ΦΠΑ», «έσοδα-έξοδα ανά μήνα». '
            .'Έτος προαιρετικό (προεπιλογή το τρέχον). Τοπικά δεδομένα + αποθηκευμένο Ε3, χωρίς κλήση ΑΑΔΕ.';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'year' => ['type' => 'integer', 'description' => 'Ημερολογιακό έτος (π.χ. 2026). Προεπιλογή: το τρέχον.'],
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

        $e = (new IncomeTaxEstimate($tenant))->forYear($year);
        $vat = VatYearOverview::for($tenant, $year);
        $m = $e['monthly'];

        return [
            'year' => $year,
            'is_current' => $e['is_current'],
            'through' => $e['through'],
            'currency' => 'EUR',
            // 'e3' closed year from the Ε3 · 'blend' running year where the Ε3 filled
            // a group the local book lacks · 'local' otherwise.
            'source' => $e['source'],
            'e3_snapshot' => $e['e3'] === null ? null : [
                'through' => $e['e3']['through'],
                'fetched_at' => $e['e3']['fetched_at'],
                'full_year' => $e['e3']['full_year'],
            ],
            'estimate' => [
                'income_total' => $e['income_total'],
                'income_adjustments' => $e['income_adjustments'],
                'expense_total' => $e['expense_total'],
                'capex_not_deducted' => $e['capex'],
                'profit' => $e['profit'],
                'tax_rate_pct' => $e['profile']->rate,
                'tax' => $e['tax'],
                'withheld' => $e['withheld'],
                'prepayment_rate_pct' => $e['profile']->prepaymentRate,
                'prepayment_next' => $e['prepayment_next'],
                'prior_prepayment' => $e['prior_prepayment'],
                // 'assessed' = entered from the εκκαθαριστικό · 'missing' = counts 0 (hint = our guess)
                'prior_prepayment_source' => $e['prior_source'],
                'prior_prepayment_hint' => $e['prior_hint'],
                'payable' => $e['is_current'] ? null : $e['payable'],
                'headline_payable' => $e['headline_payable'],
                'monthly_saving' => $e['monthly_saving'],
                'expense_warning' => $e['expense_warning'],
            ],
            // Why the running-year figure may be off (payroll months not filed, stale Ε3, …).
            'warnings' => $e['warnings'],
            // Incl. `method`: how each category was projected to 31/12.
            'projection' => $e['projection'],
            'expense_groups' => $e['expense_blend'],
            'monthly' => [
                'mode' => $m['mode'],
                'monthly_available' => $m['monthly_available'],
                'months' => array_values(array_map(
                    fn (array $cell, int $month) => ['month' => $month, 'label' => MonthlyResult::MONTH_LABELS[$month]] + $this->compact($cell),
                    $m['months'],
                    array_keys($m['months']),
                )),
                'quarters' => array_values(array_map(fn (array $q) => ['label' => $q['label']] + $this->compact($q['cell']), $m['quarters'])),
                'year' => $this->compact($m['year']),
            ],
            'vat' => [
                'quarters' => array_map(fn (array $q) => $this->vatRow($q['q']) + [
                    'carried_in' => $q['carried_in'],
                    'payable' => $q['payable'],
                    'carry_out' => $q['carry_out'],
                    'months' => array_map(fn (VatPeriodSummary $s) => $this->vatRow($s), $q['months']),
                ], $vat['quarters']),
                'year' => $this->vatRow($vat['year']),
                'payable_total' => $vat['payable_total'],
                'note' => 'Εισροές = εκπιπτόμενο ΦΠΑ: χωρίς 14.x (αντίστροφη επιβάρυνση) και 13.x (αποδείξεις λιανικής). '
                    .'Το payable_total είναι όλο το έτος — όσα τρίμηνα έχουν ήδη δηλωθεί δεν είναι ανοιχτή οφειλή.',
            ],
        ];
    }

    /**
     * A table cell with its from_e3 flag map folded into `e3_columns` (only the
     * columns that came from the Ε3) — the chat pays tokens for every byte.
     */
    private function compact(array $cell): array
    {
        $cell['e3_columns'] = array_keys(array_filter($cell['from_e3']));
        unset($cell['from_e3']);

        return $cell;
    }

    /** @return array{label: string, output_vat: float, input_vat: float, net_vat: float} */
    private function vatRow(VatPeriodSummary $s): array
    {
        return [
            'label' => $s->label,
            'output_vat' => round($s->outputVat, 2),
            'input_vat' => round($s->inputVat, 2),
            'net_vat' => round($s->netVat(), 2),
        ];
    }
}
