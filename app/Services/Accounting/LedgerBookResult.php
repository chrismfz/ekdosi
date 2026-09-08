<?php

namespace App\Services\Accounting;

/**
 * The Βιβλίο Εσόδων-Εξόδων for a period: the chronological rows plus the
 * totals the accountant reads off the bottom. Pure value object — totals are
 * derived from the rows (signed, so credit notes already subtract).
 *
 * `vatBalance()` = ΦΠΑ εκροών − εισροών (the "πόσο ΦΠΑ χρωστάω" figure); it
 * mirrors VatPeriodReport's net-VAT semantics but is computed here per-row so
 * the book is self-consistent with the lines it lists.
 */
class LedgerBookResult
{
    /** @param  list<LedgerRow>  $rows */
    public function __construct(
        public readonly array $rows,
        public readonly string $periodLabel,
    ) {}

    /** @return list<LedgerRow> */
    public function incomeRows(): array
    {
        return array_values(array_filter($this->rows, fn (LedgerRow $r) => $r->book === 'income'));
    }

    /** @return list<LedgerRow> */
    public function expenseRows(): array
    {
        return array_values(array_filter($this->rows, fn (LedgerRow $r) => $r->book === 'expense'));
    }

    public function incomeNet(): float
    {
        return $this->sum('income', 'net');
    }

    public function incomeVat(): float
    {
        return $this->sum('income', 'vat');
    }

    public function incomeGross(): float
    {
        return $this->sum('income', 'gross');
    }

    public function expenseNet(): float
    {
        return $this->sum('expense', 'net');
    }

    public function expenseVat(): float
    {
        return $this->sum('expense', 'vat');
    }

    public function expenseGross(): float
    {
        return $this->sum('expense', 'gross');
    }

    /** ΦΠΑ εκροών − εισροών. Positive = προς απόδοση. */
    public function vatBalance(): float
    {
        return round($this->incomeVat() - $this->expenseVat(), 2);
    }

    public function incomeCount(): int
    {
        return count($this->incomeRows());
    }

    public function expenseCount(): int
    {
        return count($this->expenseRows());
    }

    /**
     * Σύνολα ανά λογιστική κατηγορία for one book, in natural code order
     * (category1_2 before category1_10). Each entry:
     * ['code', 'label', 'net', 'vat', 'gross', 'count']. Unclassified rows fold
     * into a single null-code bucket so nothing is silently dropped.
     *
     * @return list<array{code:?string,label:?string,account:?string,net:float,vat:float,gross:float,count:int}>
     */
    public function categorySubtotals(string $book): array
    {
        $buckets = [];
        foreach ($this->rows as $row) {
            if ($row->book !== $book) {
                continue;
            }
            $key = $row->categoryCode ?? '';
            if (! isset($buckets[$key])) {
                $buckets[$key] = [
                    'code' => $row->categoryCode,
                    'label' => $row->categoryLabel,
                    'account' => $row->accountCode,
                    'net' => 0.0,
                    'vat' => 0.0,
                    'gross' => 0.0,
                    'count' => 0,
                ];
            }
            $buckets[$key]['net'] += $row->net;
            $buckets[$key]['vat'] += $row->vat;
            $buckets[$key]['gross'] += $row->gross;
            $buckets[$key]['count']++;
        }

        uksort($buckets, 'strnatcmp');

        return array_values(array_map(function (array $b) {
            $b['net'] = round($b['net'], 2);
            $b['vat'] = round($b['vat'], 2);
            $b['gross'] = round($b['gross'], 2);

            return $b;
        }, $buckets));
    }

    private function sum(string $book, string $field): float
    {
        $total = 0.0;
        foreach ($this->rows as $row) {
            if ($row->book === $book) {
                $total += $row->{$field};
            }
        }

        return round($total, 2);
    }
}
