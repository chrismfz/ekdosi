<?php

namespace App\Services\Accounting;

/**
 * «Φορολογικά» έσοδα / έξοδα per month → quarter → year, by column (μισθοδοσία,
 * εισφορές, αποσβέσεις, λοιπά, αγορές παγίων), AND the running-year blend of the
 * local book with AADE's Ε3 — one computation, so the table and the income-tax
 * estimate ({@see IncomeTaxEstimate}) can never disagree.
 *
 * Modes:
 *   'local' — the local book only (no usable Ε3 snapshot).
 *   'e3'    — a CLOSED year with a full-year Ε3: every figure from the Ε3.
 *   'blend' — the RUNNING year with an Ε3 snapshot: income stays local (our
 *             invoices ARE the truth); each expense group takes, PER MONTH, the
 *             LARGER of local and Ε3 — never their sum, because the Ε3 also holds
 *             the supplier invoices we imported once the accountant classifies them.
 *             A group only the Ε3 has (payroll/ΕΦΚΑ filed under the accountant's
 *             credentials — our RequestTransmittedDocs never returns those) comes
 *             from it. Personnel = payroll + contributions is compared as ONE group:
 *             a 17.1 may carry the employer contributions the Ε3 books as
 *             E3_581_002, so comparing them apart could count those twice.
 *
 * The per-month max assumes a document sits in the SAME month on both sides — true
 * for imported expenses (both carry the myDATA issueDate). A hand-typed expense
 * dated differently from its myDATA issueDate could count in two months; the
 * nightly import holds such look-alikes for the operator (--hold-manual).
 *
 * A snapshot fetched before the month split existed has no `monthly`: the months
 * then show the local book, and the blend runs once on the annual figures (the
 * year row still matches the estimate) until the next Ε3 refresh.
 */
final class MonthlyResult
{
    /** Deductible expense columns, in display order. */
    public const EXPENSE_COLUMNS = ['payroll', 'contributions', 'depreciation', 'rest'];

    public const MONTH_LABELS = [
        1 => 'Ιανουάριος', 2 => 'Φεβρουάριος', 3 => 'Μάρτιος', 4 => 'Απρίλιος', 5 => 'Μάιος', 6 => 'Ιούνιος',
        7 => 'Ιούλιος', 8 => 'Αύγουστος', 9 => 'Σεπτέμβριος', 10 => 'Οκτώβριος', 11 => 'Νοέμβριος', 12 => 'Δεκέμβριος',
    ];

    private const GROUP_LABELS = [
        'personnel' => 'Προσωπικό (μισθοδοσία, εισφορές)',
        'depreciation' => 'Αποσβέσεις',
        'rest' => 'Λοιπά έξοδα (προμηθευτές κ.λπ.)',
    ];

    /**
     * @param  'local'|'e3'|'blend'  $mode
     * @param  array<int|string, list<array{type: string, category: ?string, value: float}>>|null  $e3Monthly  E3YearSnapshot::monthly
     * @param  list<array{type: string, category: ?string, value: float}>  $e3AnnualRows  E3YearSnapshot::rows (fallback when no monthly)
     * @return array{mode: string, monthly_available: bool, months: array<int, array>, quarters: array<int, array>, year: array, groups: list<array>}
     */
    public static function build(LedgerBookResult $book, int $lastMonth, string $mode, ?array $e3Monthly, array $e3AnnualRows = []): array
    {
        $lastMonth = max(1, min(12, $lastMonth));
        $local = self::localCells($book, $lastMonth);
        $monthlyAvailable = $mode === 'local' || $e3Monthly !== null;

        $e3 = [];
        if ($e3Monthly !== null) {
            foreach ($e3Monthly as $month => $rows) {
                // Clamp (never drop): a stray month outside the window still counts.
                $m = max(1, min($lastMonth, (int) $month));
                $e3[$m] = self::add($e3[$m] ?? self::zero(), E3YearTotals::groups($rows));
            }
        }

        $months = [];
        $groups = self::emptyGroups();
        foreach (range(1, $lastMonth) as $m) {
            $l = $local[$m];
            $cell = match (true) {
                $mode === 'e3' && $monthlyAvailable => self::fromE3($e3[$m] ?? self::zero()),
                $mode === 'blend' && $monthlyAvailable => self::blend($l, $e3[$m] ?? self::zero(), $groups),
                default => self::fromLocal($l),
            };
            $months[$m] = self::finish($cell);
        }

        $year = self::sumCells($months);

        // No month split in the snapshot: the months stay local, the YEAR row (what the
        // estimate uses) comes from the Ε3 — whole (closed year) or blended once.
        if (! $monthlyAvailable) {
            $e3Year = E3YearTotals::groups($e3AnnualRows);
            $year = self::finish($mode === 'e3'
                ? self::fromE3($e3Year)
                : self::blend(self::sumRaw($local), $e3Year, $groups));
        }

        $quarters = [];
        foreach ([1, 2, 3, 4] as $q) {
            $in = array_filter($months, fn (int $m) => (int) ceil($m / 3) === $q, ARRAY_FILTER_USE_KEY);
            if ($in !== []) {
                $quarters[$q] = ['label' => "Τρίμηνο {$q}", 'cell' => self::sumCells($in), 'months' => $in];
            }
        }

        return [
            'mode' => $mode,
            'monthly_available' => $monthlyAvailable,
            'months' => $months,
            'quarters' => $quarters,
            'year' => $year,
            'groups' => $mode === 'blend' ? self::groupRows($groups) : [],
        ];
    }

    /** @return array<int, array> month => raw local sums (income, the four columns, capex) */
    private static function localCells(LedgerBookResult $book, int $lastMonth): array
    {
        $cells = [];
        foreach (range(1, $lastMonth) as $m) {
            $cells[$m] = self::zero();
        }

        foreach ($book->rows as $row) {
            $m = max(1, min($lastMonth, (int) $row->date->month));
            if ($row->book === 'income') {
                $cells[$m]['income'] += $row->net;

                continue;
            }

            $cells[$m]['capex'] += $row->capex;
            $column = match ($row->expenseBucket) {
                'payroll' => 'payroll',
                'social_security' => 'contributions',
                'depreciation' => 'depreciation',
                default => 'rest',
            };
            $cells[$m][$column] += $row->net - $row->capex;
        }

        return $cells;
    }

    /**
     * One period of the running-year blend (see the class docblock); accumulates the
     * per-group local / Ε3 / used sums into $groups for «Πώς μετρήθηκαν τα έξοδα».
     */
    private static function blend(array $l, array $e, array &$groups): array
    {
        $cell = self::fromLocal($l);

        $lp = $l['payroll'] + $l['contributions'];
        $ep = $e['payroll'] + $e['contributions'];
        if ($ep > $lp + 0.004) {
            $cell['payroll'] = $e['payroll'];
            $cell['contributions'] = $e['contributions'];
            $cell['from_e3']['payroll'] = $cell['from_e3']['contributions'] = true;
        } elseif ($e['contributions'] > $l['contributions'] + 0.004) {
            // The local total wins, but the SPLIT is the accountant's official one:
            // a local 17.1 lands whole under «Μισθοδοσία» (its employer-contribution
            // lines included), while the Ε3 books them apart (E3_581_002 / E3_585_007).
            // Same total — only the contributions move out of the payroll column.
            $cell['contributions'] = min($lp, $e['contributions']);
            $cell['payroll'] = $lp - $cell['contributions'];
            $cell['from_e3']['contributions'] = true;
        }
        self::track($groups['personnel'], $lp, $ep, max($lp, $ep));

        foreach (['depreciation', 'rest', 'capex'] as $col) {
            if ($e[$col] > $l[$col] + 0.004) {
                $cell[$col] = $e[$col];
                $cell['from_e3'][$col] = true;
            }
            if ($col !== 'capex') {
                self::track($groups[$col], $l[$col], $e[$col], max($l[$col], $e[$col]));
            }
        }

        return $cell;
    }

    private static function fromLocal(array $l): array
    {
        return $l + ['from_e3' => array_fill_keys([...self::EXPENSE_COLUMNS, 'capex', 'income'], false)];
    }

    private static function fromE3(array $e): array
    {
        return $e + ['from_e3' => array_fill_keys([...self::EXPENSE_COLUMNS, 'capex', 'income'], false)];
    }

    /** Round the raw columns and derive expense (deductible) + result. */
    private static function finish(array $cell): array
    {
        foreach (['income', ...self::EXPENSE_COLUMNS, 'capex'] as $k) {
            $cell[$k] = round($cell[$k], 2);
        }
        $cell['expense'] = round(array_sum(array_map(fn ($k) => $cell[$k], self::EXPENSE_COLUMNS)), 2);
        $cell['result'] = round($cell['income'] - $cell['expense'], 2);

        return $cell;
    }

    /** @param array<int, array> $cells finished cells */
    private static function sumCells(array $cells): array
    {
        $sum = self::fromLocal(self::zero());
        foreach ($cells as $c) {
            foreach (['income', ...self::EXPENSE_COLUMNS, 'capex'] as $k) {
                $sum[$k] += $c[$k];
            }
            foreach ($c['from_e3'] as $k => $flag) {
                $sum['from_e3'][$k] = $sum['from_e3'][$k] || $flag;
            }
        }

        return self::finish($sum);
    }

    /** @param array<int, array> $cells raw cells */
    private static function sumRaw(array $cells): array
    {
        return array_reduce($cells, fn (array $carry, array $c) => self::add($carry, $c), self::zero());
    }

    private static function add(array $a, array $b): array
    {
        foreach (self::zero() as $k => $_) {
            $a[$k] += (float) ($b[$k] ?? 0);
        }

        return $a;
    }

    private static function zero(): array
    {
        return ['income' => 0.0, 'payroll' => 0.0, 'contributions' => 0.0, 'depreciation' => 0.0, 'rest' => 0.0, 'capex' => 0.0];
    }

    private static function emptyGroups(): array
    {
        return array_map(fn () => ['local' => 0.0, 'e3' => 0.0, 'used' => 0.0], self::GROUP_LABELS);
    }

    private static function track(array &$g, float $local, float $e3, float $used): void
    {
        $g['local'] += $local;
        $g['e3'] += $e3;
        $g['used'] += $used;
    }

    /** @return list<array{key: string, label: string, local: float, e3: float, used: float, source: string}> */
    private static function groupRows(array $groups): array
    {
        $out = [];
        foreach ($groups as $key => $g) {
            [$local, $e3, $used] = [round($g['local'], 2), round($g['e3'], 2), round($g['used'], 2)];
            $out[] = [
                'key' => $key, 'label' => self::GROUP_LABELS[$key], 'local' => $local, 'e3' => $e3, 'used' => $used,
                // 'mixed' = local in some months, Ε3 in others (the used sum exceeds both).
                'source' => abs($used - $local) < 0.005 ? 'local' : (abs($used - $e3) < 0.005 ? 'e3' : 'mixed'),
            ];
        }

        return $out;
    }
}
