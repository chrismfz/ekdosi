<?php

namespace App\Services\Pos;

use App\Models\Company;
use App\Support\InvoiceScope;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

/**
 * «Ταμεία — Στατιστικά»: the till's turnover over time — per day / month / year,
 * weekday × hour, per cashier — and the same windows a year (or a week) earlier for
 * comparison. Only TILL documents (rung into a session), issued and live; a return
 * credit note subtracts. One light query per window (date, amount, credit flag,
 * cashier — no lines), aggregated in PHP so it runs the same on MariaDB and sqlite.
 *
 * «Καθαρά» here = what the customers paid minus what was refunded (payable, VAT incl.)
 * — the till's money, like the closing report; not the net-of-VAT accounting figure.
 */
class PosStats
{
    /** @var array<string, list<array{at: CarbonImmutable, amount: float, refund: bool, cashier: ?int}>> */
    private array $memo = [];

    public function __construct(private readonly Company $company) {}

    /**
     * Turnover, receipts, returns of a window.
     *
     * @return array{net: float, sales: float, refunds: float, receipts: int, returns: int, avg_basket: float}
     */
    public function totals(CarbonInterface $from, CarbonInterface $to): array
    {
        $sales = 0.0;
        $refunds = 0.0;
        $receipts = 0;
        $returns = 0;
        foreach ($this->rows($from, $to) as $r) {
            if ($r['refund']) {
                $refunds += $r['amount'];
                $returns++;
            } else {
                $sales += $r['amount'];
                $receipts++;
            }
        }

        return [
            'net' => round($sales - $refunds, 2),
            'sales' => round($sales, 2),
            'refunds' => round($refunds, 2),
            'receipts' => $receipts,
            'returns' => $returns,
            'avg_basket' => $receipts > 0 ? round($sales / $receipts, 2) : 0.0,
        ];
    }

    /** @return list<float> net per day of the month (index 0 = day 1) */
    public function dailyNet(int $year, int $month): array
    {
        $start = CarbonImmutable::create($year, $month, 1);
        $days = array_fill(0, $start->daysInMonth, 0.0);
        foreach ($this->rows($start, $start->endOfMonth()) as $r) {
            $days[$r['at']->day - 1] += $r['refund'] ? -$r['amount'] : $r['amount'];
        }

        return array_map(fn (float $v) => round($v, 2), $days);
    }

    /** @return list<float> net per month of the year (index 0 = January) */
    public function monthlyNet(int $year): array
    {
        $months = array_fill(0, 12, 0.0);
        foreach ($this->rows(CarbonImmutable::create($year, 1, 1), CarbonImmutable::create($year, 12, 31)) as $r) {
            $months[$r['at']->month - 1] += $r['refund'] ? -$r['amount'] : $r['amount'];
        }

        return array_map(fn (float $v) => round($v, 2), $months);
    }

    /** @return list<float> running total per month (a past month of the running year keeps growing; the future stays flat) */
    public function cumulativeMonthlyNet(int $year): array
    {
        $sum = 0.0;
        $now = CarbonImmutable::now();

        return array_map(function (float $v, int $i) use (&$sum, $year, $now): ?float {
            if ($year === $now->year && $i + 1 > $now->month) {
                return null;   // no line into the future
            }
            $sum += $v;

            return round($sum, 2);
        }, $this->monthlyNet($year), array_keys(array_fill(0, 12, 0)));
    }

    /**
     * Receipts and net per weekday × hour of a window — the till's busy hours.
     *
     * @return array{receipts: array<int, array<int, int>>, net: array<int, array<int, float>>, hours: list<int>}
     *                                                                                                            weekday 1 = Monday … 7 = Sunday
     */
    public function weekdayHours(CarbonInterface $from, CarbonInterface $to): array
    {
        $receipts = [];
        $net = [];
        $hours = [];
        foreach ($this->rows($from, $to) as $r) {
            $d = $r['at']->dayOfWeekIso;
            $h = $r['at']->hour;
            $hours[$h] = true;
            $receipts[$d][$h] = ($receipts[$d][$h] ?? 0) + ($r['refund'] ? 0 : 1);
            $net[$d][$h] = round(($net[$d][$h] ?? 0.0) + ($r['refund'] ? -$r['amount'] : $r['amount']), 2);
        }
        $hours = array_keys($hours);
        sort($hours);

        return ['receipts' => $receipts, 'net' => $net, 'hours' => $hours];
    }

    /** @return array<int|string, float> net per cashier id ('' = not recorded) of a window, biggest first */
    public function netByCashier(CarbonInterface $from, CarbonInterface $to): array
    {
        $by = [];
        foreach ($this->rows($from, $to) as $r) {
            $key = $r['cashier'] ?? '';
            $by[$key] = ($by[$key] ?? 0.0) + ($r['refund'] ? -$r['amount'] : $r['amount']);
        }
        arsort($by);

        return array_map(fn (float $v) => round($v, 2), $by);
    }

    /** % change (null when there is nothing to compare with). */
    public static function delta(float $now, float $before): ?float
    {
        return abs($before) < 0.005 ? null : round(100 * ($now - $before) / abs($before), 1);
    }

    /** @return list<array{at: CarbonImmutable, amount: float, refund: bool, cashier: ?int}> */
    private function rows(CarbonInterface $from, CarbonInterface $to): array
    {
        $start = CarbonImmutable::instance($from)->startOfDay();
        $end = CarbonImmutable::instance($to)->endOfDay();
        $key = $start->toDateTimeString().'|'.$end->toDateTimeString();

        return $this->memo[$key] ??= DB::table('invoices')
            ->leftJoin('invoice_types', 'invoice_types.id', '=', 'invoices.invoice_type_id')
            ->where('invoices.company_id', $this->company->getKey())
            ->whereNotNull('invoices.pos_session_id')
            ->where('invoices.local_status', 'active')
            ->whereNull('invoices.deleted_at')
            ->tap(fn ($q) => InvoiceScope::live($q, 'invoices.'))
            ->whereBetween('invoices.issued_at', [$start, $end])
            ->orderBy('invoices.issued_at')
            ->get(['invoices.issued_at', 'invoices.payable_total', 'invoices.gross_total', 'invoice_types.is_credit', 'invoices.pos_cashier_id'])
            ->map(fn ($r) => [
                'at' => CarbonImmutable::parse($r->issued_at),
                'amount' => (float) ($r->payable_total ?? $r->gross_total ?? 0),
                'refund' => (bool) $r->is_credit,
                'cashier' => $r->pos_cashier_id === null ? null : (int) $r->pos_cashier_id,
            ])
            ->all();
    }
}
