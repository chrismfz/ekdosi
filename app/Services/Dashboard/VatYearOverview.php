<?php

namespace App\Services\Dashboard;

use App\Models\Company;
use Carbon\CarbonImmutable;

/**
 * ΦΠΑ of a calendar year per quarter (with its three months) and the πιστωτικό
 * carried forward: `payable` = what the quarter's return actually asks for once an
 * earlier credit is carried in (a credit never becomes a payment; it rolls into the
 * next quarter). The one computation behind «Φορολογικά» and the `tax_overview`
 * tool, over {@see VatPeriodReport}.
 */
final class VatYearOverview
{
    /**
     * @return array{quarters: list<array{q: VatPeriodSummary, months: list<VatPeriodSummary>, carried_in: float, payable: float, carry_out: float}>, year: VatPeriodSummary, payable_total: float}
     */
    public static function for(Company $tenant, int $year): array
    {
        $report = new VatPeriodReport($tenant);
        $carry = 0.0;
        $quarters = [];
        $payableTotal = 0.0;

        foreach ([1, 2, 3, 4] as $q) {
            $start = CarbonImmutable::create($year, ($q - 1) * 3 + 1, 1);
            $summary = $report->forPeriod($start, $start->addMonths(2)->endOfMonth(), "Τρίμηνο {$q}");
            $afterCarry = round($summary->netVat() - $carry, 2);
            $payable = max(0.0, $afterCarry);
            $quarters[] = [
                'q' => $summary,
                'months' => $report->monthsOfQuarter($start),
                'carried_in' => $carry,
                'payable' => $payable,
                'carry_out' => $afterCarry < 0 ? -$afterCarry : 0.0,
            ];
            $carry = $afterCarry < 0 ? -$afterCarry : 0.0;
            $payableTotal += $payable;
        }

        return [
            'quarters' => $quarters,
            'year' => $report->forPeriod(CarbonImmutable::create($year, 1, 1), CarbonImmutable::create($year, 12, 31)->endOfDay(), (string) $year),
            'payable_total' => round($payableTotal, 2),
        ];
    }
}
