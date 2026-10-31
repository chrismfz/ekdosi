<?php

namespace App\Services\Accounting;

use App\Models\Company;
use Carbon\CarbonImmutable;

/**
 * «Παρακρατήσεις» — the income tax our customers withheld on our invoices, for a
 * year: per customer (to match against the βεβαίωση παρακράτησης they send) and
 * per document. Built on the SAME income book as «Φορολογικά» ({@see LedgerBook},
 * same window as IncomeTaxEstimate), so the year total is exactly the
 * «Παρακρατήσεις φόρου στα τιμολόγιά μας» line there: live documents only, a
 * credit note's withholding signed negative.
 */
class WithholdingsReport
{
    /**
     * @return array{year:int, total_withheld:float, total_net:float, total_docs:int,
     *   by_customer:list<array{customer:?string, afm:?string, docs:int, net:float, withheld:float}>,
     *   documents:list<array{id:?int, date:string, doc:string, customer:?string, afm:?string,
     *     net:float, gross:float, withheld:float, rate:?float, is_credit:bool}>}
     */
    public function build(Company $tenant, int $year): array
    {
        $today = CarbonImmutable::now();
        $start = CarbonImmutable::create($year, 1, 1)->startOfDay();
        $through = $year === $today->year ? $today->endOfDay() : $start->endOfYear();

        $rows = array_values(array_filter(
            (new LedgerBook($tenant))->forPeriod($start, $through, 'income')->rows,
            fn (LedgerRow $r) => round($r->withheld, 2) != 0.0,
        ));

        $documents = array_map(fn (LedgerRow $r) => [
            'id' => $r->recordId,
            'date' => $r->date->format('d/m/Y'),
            'doc' => $r->doc,
            'customer' => $r->counterparty,
            'afm' => $r->afm,
            'net' => $r->net,
            'gross' => $r->gross,
            'withheld' => $r->withheld,
            // Indicative only (legacy imports carry the amount, not the category).
            'rate' => $r->net != 0.0 ? round($r->withheld / $r->net * 100, 2) : null,
            'is_credit' => $r->isCredit,
        ], $rows);

        $byCustomer = [];
        foreach ($documents as $d) {
            $key = ($d['afm'] ?? '').'|'.($d['customer'] ?? '');
            $byCustomer[$key] ??= ['customer' => $d['customer'], 'afm' => $d['afm'], 'docs' => 0, 'net' => 0.0, 'withheld' => 0.0];
            $byCustomer[$key]['docs']++;
            $byCustomer[$key]['net'] += $d['net'];
            $byCustomer[$key]['withheld'] += $d['withheld'];
        }
        $byCustomer = array_map(fn (array $c) => ['net' => round($c['net'], 2), 'withheld' => round($c['withheld'], 2)] + $c, array_values($byCustomer));
        usort($byCustomer, fn (array $a, array $b) => $b['withheld'] <=> $a['withheld']);

        return [
            'year' => $year,
            'total_withheld' => round(array_sum(array_column($documents, 'withheld')), 2),
            'total_net' => round(array_sum(array_column($documents, 'net')), 2),
            'total_docs' => count($documents),
            'by_customer' => $byCustomer,
            'documents' => $documents,
        ];
    }
}
