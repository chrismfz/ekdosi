<?php

namespace App\Services\Accounting;

use App\Models\Company;
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
     *   income:float, income_adjustments:float, income_total:float,
     *   expense_total:float, expense_breakdown:list<array>, profit:float, withheld:float,
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

        // Running year: extrapolate the year-to-date result to 31/12 by elapsed days
        // (only once there's a month of data to extrapolate from).
        if ($core['is_current']) {
            // Calendar days (dayOfYear), not a float diff across the DST switch.
            $days = $today->dayOfYear;
            $yearDays = $today->daysInYear;
            if ($days >= self::MIN_PROJECTION_DAYS && $days < $yearDays) {
                $factor = $yearDays / max(1, $days);
                $pIncome = round($core['income_total'] * $factor, 2);
                $pExpense = round($core['expense_total'] * $factor, 2);
                $pWithheld = round($core['withheld'] * $factor, 2);
                $out['projection'] = [
                    'elapsed_days' => $days,
                    'year_days' => $yearDays,
                    'income_total' => $pIncome,
                    'expense_total' => $pExpense,
                    'profit' => round($pIncome - $pExpense, 2),
                    'withheld' => $pWithheld,
                ] + $this->settle(round($pIncome - $pExpense, 2), $pWithheld, $prior, $profile);
            }
        }

        // The HEADLINE «what we'll owe». A closed year: its payable. The running
        // year: the 31/12 projection — the year-to-date payable subtracts the FULL
        // prior prepayment from a PARTIAL year's tax, which reads as a fake refund
        // until ~mid-year. No projection yet (first month) → null, «λίγα δεδομένα».
        $out['headline_payable'] = $core['is_current'] ? ($out['projection']['payable'] ?? null) : $out['payable'];

        // Running year only: spread what we'll owe over the months left in the
        // year (incl. this one), so it's put aside by 31/12. Null for closed years.
        $out['monthly_saving'] = null;
        if ($core['is_current'] && $out['headline_payable'] !== null) {
            $monthsLeft = 13 - $today->month;
            $out['monthly_saving'] = $out['headline_payable'] > 0 ? round($out['headline_payable'] / $monthsLeft, 2) : 0.0;
        }

        return $out;
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

        $incomeTotal = $book->incomeNet();
        $expense = $book->expenseNet();

        return $this->core[$year] = [
            'is_current' => $isCurrent,
            'through' => $through->toDateString(),
            'doc_count' => count($book->rows),
            'income' => round($incomeTotal - $adjustments, 2),
            'income_adjustments' => $adjustments,
            'income_total' => $incomeTotal,
            'expense_total' => $expense,
            'expense_breakdown' => $book->expenseBreakdown(),
            'profit' => round($incomeTotal - $expense, 2),
            'withheld' => $book->incomeWithheld(),
            // Expenses under 10% of income usually means they weren't imported for
            // that year (myDATA expense import is recent) — the profit is then overstated.
            'expense_warning' => $incomeTotal > 0 && $expense < 0.1 * $incomeTotal,
        ];
    }
}
