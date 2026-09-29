<?php

namespace App\Services\Assistant\Tools;

use App\Models\Company;
use App\Models\E3YearSnapshot;
use App\Services\Accounting\IncomeTaxEstimate;
use App\Services\Accounting\LedgerBook;
use App\Services\Accounting\MonthlyResult;
use Carbon\CarbonImmutable;

/**
 * «Is the picture complete?» for a year — the diagnostics behind a suspicious
 * «Φορολογικά» figure: up to when each expense category has been posted, which
 * months have NO payroll on either side (the accountant hasn't filed them yet), and
 * per month where the local book and the AADE Ε3 disagree (the raw sides from
 * {@see MonthlyResult}, before any blend) with what the gap usually means.
 * Local data + the stored Ε3 snapshot; no AADE call.
 */
class DataFreshnessTool implements AssistantTool
{
    use ReadsTaxYear;

    /** A month's local↔Ε3 gap is reported from this many € … */
    private const MIN_DIFF = 50.0;

    /** … and this share of the larger side (rounding noise stays quiet). */
    private const MIN_DIFF_SHARE = 0.05;

    public function name(): string
    {
        return 'data_freshness';
    }

    public function description(): string
    {
        return 'Διάγνωση πληρότητας δεδομένων για ένα έτος (γιατί ένα νούμερο στα «Φορολογικά» μοιάζει λάθος): ως '
            .'πότε έχει περαστεί κάθε κατηγορία εξόδων (`last_date`), ποιοι μήνες ΔΕΝ έχουν μισθοδοσία ούτε τοπικά '
            .'ούτε στο Ε3 (δεν τη διαβίβασε ακόμα ο λογιστής), και ανά μήνα πού διαφέρουν τα τοπικά από το Ε3 της '
            .'ΑΑΔΕ (προσωπικό / αποσβέσεις / λοιπά) με το τι σημαίνει συνήθως η διαφορά, + πότε ανανεώθηκε το Ε3. '
            .'Για «λείπει κάτι;», «γιατί ο φόρος βγαίνει υψηλός», «έχει περάσει ο λογιστής τη μισθοδοσία;». '
            .'Τοπικά δεδομένα + αποθηκευμένο Ε3, χωρίς κλήση ΑΑΔΕ.';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'year' => ['type' => 'integer', 'description' => 'Ημερολογιακό έτος. Προεπιλογή: το τρέχον.'],
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

        $today = CarbonImmutable::now();
        $isCurrent = $year === (int) $today->year;
        $through = $isCurrent ? $today->endOfDay() : CarbonImmutable::create($year, 12, 31)->endOfDay();
        $book = (new LedgerBook($tenant))->forPeriod(CarbonImmutable::create($year, 1, 1), $through);
        $compare = (new IncomeTaxEstimate($tenant))->forYear($year)['monthly']['compare'];
        $snap = E3YearSnapshot::query()->where('company_id', $tenant->getKey())->where('year', $year)->first();

        $incomeDates = array_map(fn ($r) => $r->date->toDateString(), $book->incomeRows());

        // Months that are over (the running month is still being posted).
        $lastComplete = $isCurrent ? (int) $today->month - 1 : 12;

        return [
            'year' => $year,
            'through' => $through->toDateString(),
            'income' => ['count' => count($incomeDates), 'last_date' => $incomeDates === [] ? null : max($incomeDates)],
            'expense_categories' => array_map(fn (array $b) => [
                'category' => $b['bucket'], 'label' => $b['label'], 'count' => $b['count'],
                'net' => $b['net'], 'last_date' => $b['last_date'],
            ], $book->expenseBreakdown()),
            'e3_snapshot' => $snap === null ? null : [
                'fetched_at' => $snap->fetched_at->toDateTimeString(),
                'through' => $snap->through->toDateString(),
                'monthly_available' => $snap->monthly !== null,
            ],
            'payroll_missing_months' => array_map(
                fn (int $m) => MonthlyResult::MONTH_LABELS[$m],
                MonthlyResult::payrollGaps($compare, $lastComplete),
            ),
            'local_vs_e3' => $snap === null || $snap->monthly === null ? null : $this->differences($compare),
            'notes' => $this->notes($snap, $book->expenseBreakdown()),
        ];
    }

    /** @return list<array{month: int, label: string, group: string, local: float, e3: float, diff: float, meaning: string}> */
    private function differences(array $compare): array
    {
        $out = [];
        foreach ($compare as $m => $c) {
            $sides = [
                'personnel' => [$c['local']['payroll'] + $c['local']['contributions'], $c['e3']['payroll'] + $c['e3']['contributions']],
                'depreciation' => [$c['local']['depreciation'], $c['e3']['depreciation']],
                'rest' => [$c['local']['rest'], $c['e3']['rest']],
            ];
            foreach ($sides as $group => [$local, $e3]) {
                $diff = round($e3 - $local, 2);
                if (abs($diff) < max(self::MIN_DIFF, self::MIN_DIFF_SHARE * max(abs($local), abs($e3)))) {
                    continue;
                }
                $out[] = [
                    'month' => $m, 'label' => MonthlyResult::MONTH_LABELS[$m], 'group' => $group,
                    'local' => round($local, 2), 'e3' => round($e3, 2), 'diff' => $diff,
                    'meaning' => $diff > 0
                        ? 'Το Ε3 έχει περισσότερα: εγγραφές του λογιστή που δεν ήρθαν σε εμάς ως έγγραφα (π.χ. με δικούς του κωδικούς) — μετράει το Ε3.'
                        : 'Τα τοπικά έχουν περισσότερα: παραστατικά που ο λογιστής δεν έχει χαρακτηρίσει ακόμα στο Ε3 — μετράνε τα τοπικά.',
                ];
            }
        }

        return $out;
    }

    /** @return list<string> */
    private function notes(?E3YearSnapshot $snap, array $breakdown): array
    {
        $notes = [];
        if ($snap === null) {
            $notes[] = 'Δεν υπάρχει Ε3 για το έτος — η σύγκριση με το Ε3 δεν είναι διαθέσιμη (ανανεώνεται κάθε νύχτα ή με «Ανανέωση Ε3»).';
        } elseif ($snap->monthly === null) {
            $notes[] = 'Το αποθηκευμένο Ε3 δεν έχει ανάλυση ανά μήνα — θα ξαναφορτωθεί αυτόματα από το νυχτερινό job.';
        }

        $hasSelfEmployedInsurance = $snap !== null && collect($snap->rows ?? [])->contains(fn ($r) => ($r['type'] ?? null) === 'E3_585_007');
        $hasLocalSocialSecurity = collect($breakdown)->contains(fn ($b) => $b['bucket'] === 'social_security');
        if (! $hasSelfEmployedInsurance && ! $hasLocalSocialSecurity) {
            $notes[] = 'Καμία εισφορά αυτοαπασχολούμενων (ΕΦΚΑ εταίρων, E3_585_007) ούτε τοπικά ούτε στο Ε3 — σε ΟΕ/ΕΕ ο '
                .'λογιστής συχνά τις περνά στο κλείσιμο· μέχρι τότε η εκτίμηση φόρου τις αγνοεί.';
        }

        return $notes;
    }
}
