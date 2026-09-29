<?php

namespace App\Services\Accounting;

use App\Models\Company;
use App\Models\E3YearSnapshot;
use App\Models\Expense;
use App\Models\Invoice;
use App\Support\Accounting\IncomeTaxProfile;
use App\Support\MyData\Codes;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * «Τι θα πληρώσουμε στην εφορία» — a PLANNING estimate of the year's income tax
 * for a company (ΟΕ/ΕΕ/ΙΚΕ/ΑΕ: flat rate on the profit, no κλίμακα/τεκμήρια),
 * built only on documents we already hold via {@see LedgerBook}:
 *
 *   κέρδος      = έσοδα (our invoices + myDATA 17.3/17.4 income adjustments)
 *               − έξοδα (supplier docs + what the accountant self-declares:
 *                 μισθοδοσία 17.1, αποσβέσεις 17.2, ΕΦΚΑ 14.5, 17.5/17.6 …)
 *                 WITHOUT αγορές παγίων (E3_882/883 — deducted via αποσβέσεις)
 *   φόρος       = max(0, κέρδος) × rate
 *   προκαταβολή = max(0, φόρος × prepayment_rate − παρακρατήσεις)   (for next year)
 *   υπόλοιπο    = φόρος − παρακρατήσεις + προκαταβολή − προκαταβολή που βεβαιώθηκε πέρσι
 *
 * The previous year's prepayment is ONLY the operator-entered ΒΕΒΑΙΩΜΕΝΗ amount
 * ({@see IncomeTaxProfile}); our estimate from last year's data is a hint (see
 * priorPrepayment() for why it is never subtracted).
 * For the running year a straight-line projection to 31/12 is added — crude
 * (payroll is often posted per quarter), and labelled as such on the page.
 *
 * SOURCE per year: a CLOSED year with an Ε3 snapshot ({@see E3YearTotals}) takes
 * income/expense/capex from AADE's Ε3 (the accountant's final classification);
 * otherwise from the local book. The RUNNING year with an Ε3 snapshot blends the
 * two per expense group and month ({@see MonthlyResult}: the larger side, never the sum) — the
 * accountant's payroll/ΕΦΚΑ often reach AADE only under their credentials. The
 * other side is returned alongside as a cross-check. Withholdings are always local.
 *
 * Read-only, tenant-scoped through LedgerBook (explicit company_id). NOT a tax
 * return: accounting profit ≠ taxable profit (non-deductibles, inventory,
 * provisions) unless the accountant posts the 17.4/17.6 tax-basis adjustments.
 */
class IncomeTaxEstimate
{
    /** No projection before this many days of data — one early invoice × 180 is noise. */
    public const MIN_PROJECTION_DAYS = 30;

    /** @var array<int, array> per-year core figures, memoised (the multi-year table reuses them) */
    private array $core = [];

    public function __construct(private readonly Company $tenant) {}

    /**
     * @return array{
     *   year:int, is_current:bool, through:string, profile:IncomeTaxProfile,
     *   source:string, e3:?array, local:array,
     *   income:float, income_adjustments:float, income_total:float,
     *   expense_all:float, capex:float, expense_total:float, expense_breakdown:list<array>, profit:float, withheld:float,
     *   tax:float, prepayment_next:float, prior_prepayment:float, prior_source:string, prior_hint:?float,
     *   expense_warning:bool, headline_payable:?float, monthly_saving:?float,
     *   payable:float, projection:?array
     * }
     */
    public function forYear(int $year, ?CarbonInterface $today = null): array
    {
        $today = CarbonImmutable::instance($today ?? now());
        $profile = IncomeTaxProfile::for($this->tenant);
        $core = $this->core($year, $today);

        [$prior, $priorSource, $priorHint] = $this->priorPrepayment($year, $today, $profile);

        $out = $core + $this->settle($core['profit'], $core['withheld'], $prior, $profile) + [
            'year' => $year,
            'profile' => $profile,
            'prior_prepayment' => $prior,
            'prior_source' => $priorSource,
            'prior_hint' => $priorHint,
            'projection' => null,
        ];

        // Running year: project the year-to-date result to 31/12 PER CATEGORY (see
        // project()) — only once there's a month of data to project from.
        $out['warnings'] = [];
        if ($core['is_current']) {
            $snapshot = E3YearSnapshot::query()->where('company_id', $this->tenant->getKey())->where('year', $year)->first();
            $out['projection'] = $this->project($year, $core, $today, $profile, $prior, $snapshot);
            $out['warnings'] = $this->warnings($core, $today, $profile, $snapshot);
        }

        // The HEADLINE «what we'll owe». A closed year: its payable. The running
        // year: the 31/12 projection — the year-to-date payable subtracts the FULL
        // prior prepayment from a PARTIAL year's tax, which reads as a fake refund
        // until ~mid-year. No projection yet (first month) → null, «λίγα δεδομένα».
        // On 31/12 the year-to-date IS the full year (no projection needed).
        $yearComplete = $core['is_current'] && $today->dayOfYear === $today->daysInYear;
        $out['headline_payable'] = $core['is_current'] && ! $yearComplete
            ? ($out['projection']['payable'] ?? null)
            : $out['payable'];

        // Running year only: spread what we'll owe over the months left in the
        // year (incl. this one), so it's put aside by 31/12. Null for closed years.
        $out['monthly_saving'] = null;
        if ($core['is_current'] && $out['headline_payable'] !== null) {
            $monthsLeft = 13 - $today->month;
            $out['monthly_saving'] = $out['headline_payable'] > 0 ? round($out['headline_payable'] / $monthsLeft, 2) : 0.0;
        }

        return $out;
    }

    /** Days after which a running-year Ε3 snapshot counts as stale (the nightly job should refresh it). */
    public const E3_STALE_DAYS = 3;

    /**
     * The running-year 31/12 projection, PER CATEGORY instead of one linear factor —
     * the linear one read a month the accountant hasn't filed yet (payroll) as zero
     * cost for the rest of the year, and year-end postings (αποσβέσεις, ΕΦΚΑ εταίρων)
     * as never coming:
     *   - income       : last year's month pattern from its full-year Ε3 (the share of
     *                    the year done by today), else linear by elapsed days;
     *   - personnel    : the monthly rate (median of the last 3 posted months when
     *                    posted monthly — a bonus month doesn't skew it; else the
     *                    average per month since January) × the months after
     *                    the last posted one, + one month for the δώρο Χριστουγέννων;
     *   - depreciation : linear when posted during the year, else last year's (the
     *                    accountant posts them at the close);
     *   - rest         : linear by elapsed days;
     *   - + the profile's expected partners' ΕΦΚΑ while none of it has appeared.
     * Withholdings stay linear. Null before MIN_PROJECTION_DAYS or on 31/12.
     */
    private function project(int $year, array $core, CarbonImmutable $today, IncomeTaxProfile $profile, float $prior, ?E3YearSnapshot $snapshot): ?array
    {
        $days = $today->dayOfYear;
        $yearDays = $today->daysInYear;
        if ($days < self::MIN_PROJECTION_DAYS || $days >= $yearDays) {
            return null;
        }
        $elapsed = $days / $yearDays;
        $months = $core['monthly']['months'];
        $previous = $this->fullYearSnapshot($year - 1);

        // Income.
        [$pIncome, $incomeMethod, $share] = [round($core['income_total'] / $elapsed, 2), 'linear', null];
        if ($previous?->monthly !== null) {
            $byMonth = [];
            foreach ($previous->monthly as $m => $rows) {
                $byMonth[(int) $m] = E3YearTotals::groups($rows)['income'];
            }
            $total = array_sum($byMonth);
            $cur = (int) $today->month;
            $done = array_sum(array_filter($byMonth, fn (int $m) => $m < $cur, ARRAY_FILTER_USE_KEY))
                + ($byMonth[$cur] ?? 0.0) * $today->day / $today->daysInMonth;
            // A share outside [¼, 1) means last year isn't a usable pattern (e.g. a partial first year).
            if ($total > 0 && $done / $total >= 0.25 && $done / $total < 1) {
                $share = round($done / $total, 4);
                [$pIncome, $incomeMethod] = [round($core['income_total'] / $share, 2), 'seasonal'];
            }
        }

        // Personnel.
        $personnel = array_map(fn (array $c) => $c['payroll'] + $c['contributions'], $months);
        $ytdPersonnel = array_sum($personnel);
        $posted = array_keys(array_filter($personnel, fn (float $v) => $v > 0.004));
        [$rate, $remaining, $bonus, $personnelMethod] = [0.0, 0, 0.0, 'none'];
        if ($posted !== []) {
            [$first, $last] = [min($posted), max($posted)];
            // «Monthly» when payroll sits in ≥ ¾ of the months of its span — one month the
            // accountant skipped doesn't flip it; a per-quarter batch (1 in 3) stays a batch.
            if (count($posted) >= 0.75 * ($last - $first + 1)) {
                $recent = array_slice(array_map(fn (int $m) => $personnel[$m], $posted), -3);
                sort($recent);
                $n = count($recent);
                $rate = $n % 2 ? $recent[intdiv($n, 2)] : ($recent[$n / 2 - 1] + $recent[$n / 2]) / 2;
                $personnelMethod = 'monthly_median';
            } else {
                // Posted in batches (e.g. per quarter, at its end): the months before
                // the first batch are INSIDE it, so average over January → last.
                $rate = $ytdPersonnel / $last;
                $personnelMethod = 'average_per_month';
            }
            $remaining = 12 - $last;
            $bonus = $remaining > 0 ? $rate : 0.0;   // δώρο Χριστουγέννων ≈ one month, paid in December
        }
        $pPersonnel = $ytdPersonnel + $rate * $remaining + $bonus;

        // Depreciation.
        $ytdDepreciation = array_sum(array_column($months, 'depreciation'));
        [$pDepreciation, $depreciationMethod] = [0.0, 'none'];
        if ($ytdDepreciation > 0.004) {
            [$pDepreciation, $depreciationMethod] = [$ytdDepreciation / $elapsed, 'linear'];
        } elseif ($previous !== null && ($lastYear = E3YearTotals::groups($previous->rows ?? [])['depreciation']) > 0.004) {
            [$pDepreciation, $depreciationMethod] = [$lastYear, 'previous_year'];
        }

        // Rest (suppliers etc.).
        $pRest = array_sum(array_column($months, 'rest')) / $elapsed;

        // Partners' ΕΦΚΑ the accountant posts at the close: only while none has appeared.
        $insurancePresent = $this->partnerInsurancePresent($core, $snapshot);
        $insuranceAdded = $insurancePresent > 0.004 ? 0.0 : $profile->expectedPartnerInsurance;

        $pExpense = round($pRest + $pPersonnel + $pDepreciation + $insuranceAdded, 2);
        $pWithheld = round($core['withheld'] / $elapsed, 2);

        return [
            'elapsed_days' => $days,
            'year_days' => $yearDays,
            'income_total' => $pIncome,
            'expense_total' => $pExpense,
            'profit' => round($pIncome - $pExpense, 2),
            'withheld' => $pWithheld,
            'method' => [
                'income' => ['method' => $incomeMethod, 'share_done' => $share, 'value' => $pIncome],
                'personnel' => ['method' => $personnelMethod, 'ytd' => round($ytdPersonnel, 2), 'monthly_rate' => round($rate, 2),
                    'months_remaining' => $remaining, 'christmas_bonus' => round($bonus, 2), 'value' => round($pPersonnel, 2)],
                'depreciation' => ['method' => $depreciationMethod, 'value' => round($pDepreciation, 2)],
                'rest' => ['method' => 'linear', 'value' => round($pRest, 2)],
                'partner_insurance' => ['expected' => $profile->expectedPartnerInsurance, 'present' => round($insurancePresent, 2), 'added' => $insuranceAdded],
            ],
        ] + $this->settle(round($pIncome - $pExpense, 2), $pWithheld, $prior, $profile);
    }

    /**
     * What should make the operator distrust the running-year figure, in plain Greek:
     * payroll months not filed yet, a stale Ε3, partners' ΕΦΚΑ nowhere in sight.
     *
     * @return list<string>
     */
    private function warnings(array $core, CarbonImmutable $today, IncomeTaxProfile $profile, ?E3YearSnapshot $snapshot): array
    {
        $out = [];

        $gaps = MonthlyResult::payrollGaps($core['monthly']['compare'], (int) $today->month - 1);
        if ($gaps !== []) {
            $out[] = 'Μισθοδοσία: δεν έχει περαστεί ακόμα για '.implode(', ', array_map(fn (int $m) => MonthlyResult::MONTH_LABELS[$m], $gaps))
                .'. Η προβολή 31/12 τη μετρά με τον ρυθμό των τελευταίων μηνών.';
        }

        if ($snapshot !== null && $snapshot->fetched_at->lt($today->subDays(self::E3_STALE_DAYS))) {
            $out[] = 'Το Ε3 ανανεώθηκε τελευταία στις '.$snapshot->fetched_at->format('d/m/Y')
                .' — ελέγξτε το νυχτερινό «myDATA — εισαγωγή εξόδων + Ε3» (Χρονοπρογραμματιστής).';
        }

        if ($this->partnerInsurancePresent($core, $snapshot) < 0.005 && $profile->expectedPartnerInsurance <= 0) {
            $out[] = 'Δεν έχουν εμφανιστεί εισφορές εταίρων (ΕΦΚΑ αυτοαπασχολούμενων). Αν τις περνά ο λογιστής στο κλείσιμο, '
                .'ορίστε το αναμενόμενο ετήσιο ποσό στο «Φορολογικό προφίλ» ώστε να μετρά στην προβολή.';
        }

        return $out;
    }

    /** Partners' ΕΦΚΑ seen so far this year: the Ε3's E3_585_007 or our own 14.5 — whichever is larger (never both). */
    private function partnerInsurancePresent(array $core, ?E3YearSnapshot $snapshot): float
    {
        $e3 = collect($snapshot?->rows ?? [])->where('type', 'E3_585_007')->sum('value');
        $local = collect($core['expense_breakdown'])->where('bucket', 'social_security')->sum('net');

        return max((float) $e3, (float) $local);
    }

    private function fullYearSnapshot(int $year): ?E3YearSnapshot
    {
        $s = E3YearSnapshot::query()->where('company_id', $this->tenant->getKey())->where('year', $year)->first();

        return $s !== null && E3YearTotals::coversFullYear($s) ? $s : null;
    }

    /**
     * One row per year, newest first, for the «ανά έτος» table.
     *
     * @return list<array>
     */
    public function yearsSummary(array $years, ?CarbonInterface $today = null): array
    {
        rsort($years);

        return array_map(fn (int $y) => $this->forYear($y, $today), $years);
    }

    /** Years that hold any invoice or expense, newest first (always incl. the current). */
    public function availableYears(?CarbonInterface $today = null): array
    {
        $now = (int) ($today ?? now())->year;
        $id = $this->tenant->getKey();

        $lo = array_filter([
            Invoice::query()->where('company_id', $id)->min('issued_at'),
            Expense::query()->where('company_id', $id)->min('issue_date'),
        ]);
        $first = $lo === [] ? $now : min($now, ...array_map(fn ($d) => (int) CarbonImmutable::parse($d)->year, $lo));

        return range($now, $first);
    }

    /** φόρος / προκαταβολή / υπόλοιπο for a profit — the one place the formula lives. */
    private function settle(float $profit, float $withheld, float $prior, IncomeTaxProfile $profile): array
    {
        $tax = round(max(0.0, $profit) * $profile->rate / 100, 2);
        $prepayment = round(max(0.0, $tax * $profile->prepaymentRate / 100 - $withheld), 2);
        $payable = round($tax - $withheld + $prepayment - $prior, 2);

        return [
            'tax' => $tax,
            'prepayment_next' => $prepayment,
            'payable' => $payable,
        ];
    }

    /**
     * The prepayment credited against this year's tax. It is a KNOWN figure (the
     * εκκαθαριστικό of last year's return), so only the operator-entered ΒΕΒΑΙΩΜΕΝΗ
     * amount is ever subtracted. Our own estimate from last year's data is returned
     * as a HINT only: when earlier years' expenses were never imported (myDATA
     * expense import started later), last year's "profit" is inflated and the
     * guessed prepayment would show a fake refund. Unset → 0 (over-saving is the
     * safe error).
     *
     * @return array{0: float, 1: string, 2: ?float} amount, 'assessed' | 'missing', hint
     */
    private function priorPrepayment(int $year, CarbonImmutable $today, IncomeTaxProfile $profile): array
    {
        $prev = $this->core($year - 1, $today);
        $hint = $prev['doc_count'] === 0
            ? null
            : $this->settle($prev['profit'], $prev['withheld'], 0.0, $profile)['prepayment_next'];

        $assessed = $profile->assessedPrepaymentFor($year);

        return $assessed !== null ? [$assessed, 'assessed', $hint] : [0.0, 'missing', $hint];
    }

    /** The data-driven figures of one year (no profile applied). */
    private function core(int $year, CarbonImmutable $today): array
    {
        if (isset($this->core[$year])) {
            return $this->core[$year];
        }

        $start = CarbonImmutable::create($year, 1, 1)->startOfDay();
        $end = $start->endOfYear();
        $isCurrent = $year === $today->year;
        $through = $isCurrent ? $today->endOfDay() : $end;

        $book = (new LedgerBook($this->tenant))->forPeriod($start, $through);

        // 17.3/17.4 «τακτοποιήσεις εσόδων» are already on the income side (LedgerBook
        // books them there); broken out here only for display.
        $adjustments = round(array_sum(array_map(
            fn (LedgerRow $r) => $r->net,
            array_filter($book->incomeRows(), fn (LedgerRow $r) => in_array($r->docType, Codes::INCOME_ADJUSTMENT_TYPES, true)),
        )), 2);

        $localIncome = $book->incomeNet();
        // Αγορές παγίων (E3_882/883) are capital expenditure: deducted over the years
        // via αποσβέσεις (17.2 / E3_587), never as an expense of the year they're bought.
        $localCapex = $book->expenseCapex();
        $localExpenseAll = $book->expenseNet();
        $localExpense = round($localExpenseAll - $localCapex, 2);

        // A CLOSED year with an Ε3 snapshot: AADE's Ε3 is the accountant's FINAL
        // classification (a later re-classification — e.g. a 17.5 re-booked as αγορά
        // παγίου — never reaches our local lines), so it is the source. The running
        // year stays local: the accountant posts the Ε3 per quarter, it lags.
        $snapshot = E3YearSnapshot::query()
            ->where('company_id', $this->tenant->getKey())
            ->where('year', $year)
            ->first();
        // Only a snapshot that covers the WHOLE year (taken after 31/12): one taken
        // mid-year must never turn into the «final» figure once the year closes.
        $useE3 = $snapshot !== null && ! $isCurrent && E3YearTotals::coversFullYear($snapshot);

        $incomeTotal = $useE3 ? (float) $snapshot->income : $localIncome;
        $expense = $useE3 ? (float) $snapshot->expense : $localExpense;
        $capex = $useE3 ? (float) $snapshot->capex : $localCapex;

        // Month-by-month έσοδα/έξοδα — and, for the RUNNING year with an Ε3 snapshot
        // (refreshed nightly by mydata:sync-expenses), the blend with the Ε3: the
        // accountant's entries often reach AADE under THEIR credentials, so our
        // RequestTransmittedDocs never returns them, but the Ε3 counts them. The
        // estimate reads its expense from the SAME computation as the page's table.
        $mode = $useE3 ? 'e3' : ($isCurrent && $snapshot !== null ? 'blend' : 'local');
        $monthly = MonthlyResult::build($book, $isCurrent ? $today->month : 12, $mode, $snapshot?->monthly, $snapshot?->rows ?? []);
        $blendUsedE3 = collect($monthly['groups'])->contains(fn (array $g) => $g['source'] !== 'local');
        if ($mode === 'blend') {
            $expense = $monthly['year']['expense'];
            $capex = $monthly['year']['capex'];
        }
        $expenseAll = round($expense + $capex, 2);

        return $this->core[$year] = [
            'is_current' => $isCurrent,
            'through' => $through->toDateString(),
            'doc_count' => count($book->rows),
            // 'e3' closed year from the Ε3 · 'blend' running year where the Ε3 filled a
            // group the local book lacks · 'local' otherwise.
            'source' => $useE3 ? 'e3' : ($mode === 'blend' && $blendUsedE3 ? 'blend' : 'local'),
            'expense_blend' => $mode === 'blend' ? $monthly['groups'] : null,
            'monthly' => $monthly,
            'e3' => $snapshot === null ? null : [
                'income' => (float) $snapshot->income,
                'expense' => (float) $snapshot->expense,
                'capex' => (float) $snapshot->capex,
                'through' => $snapshot->through->toDateString(),
                'fetched_at' => $snapshot->fetched_at->toDateTimeString(),
                'full_year' => E3YearTotals::coversFullYear($snapshot),
                'review' => E3YearTotals::reviewFlags($snapshot->rows ?? []),
            ],
            'local' => [
                'income' => $localIncome, 'expense' => $localExpense, 'capex' => $localCapex,
                'expense_all' => $localExpenseAll,  // = Σ of the (local) expense_breakdown
            ],
            'income' => round($incomeTotal - ($useE3 ? 0.0 : $adjustments), 2),
            'income_adjustments' => $useE3 ? 0.0 : $adjustments,
            'income_total' => $incomeTotal,
            'expense_all' => $expenseAll,   // everything in the book (incl. πάγια)
            'capex' => $capex,
            'expense_total' => $expense,    // deductible: without αγορές παγίων
            'expense_breakdown' => $book->expenseBreakdown(),
            'profit' => round($incomeTotal - $expense, 2),
            'withheld' => $book->incomeWithheld(),
            // Expenses under 10% of income usually means they weren't imported for
            // that year (myDATA expense import is recent) — the profit is then overstated.
            // (Local source only — the Ε3 is the accountant's own figure.)
            'expense_warning' => ! $useE3 && $incomeTotal > 0 && $expense < 0.1 * $incomeTotal,
        ];
    }
}
