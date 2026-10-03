<?php

namespace App\Services\Pos;

use App\Models\Company;
use App\Models\Invoice;
use App\Models\PosCashMovement;
use App\Models\PosSession;
use App\Models\Scopes\CompanyScope;
use App\Models\User;
use App\Services\InvoiceVatBreakdown;
use App\Support\InvoiceScope;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * «Ταμείο ημέρας» (POS PR 2b, docs/woocommerce-bridge-plan.md §11.2): open the till
 * with a cash float, put cash in / take it out, close it with a count. The report
 * (per payment method, per VAT rate, cash expected vs counted) is INTERNAL — the
 * legal documents are the receipts themselves (filed one by one); this is not a Ζ.
 *
 * One open session per company at a time (2c adds registers). Every till document
 * carries its session (`invoices.pos_session_id`), so a report is exactly the
 * documents rung in it. At closing the report is FROZEN on the session.
 */
class TillSessions
{
    public function current(Company $company): ?PosSession
    {
        return PosSession::query()->withoutGlobalScope(CompanyScope::class)
            ->where('company_id', $company->getKey())
            ->whereNull('closed_at')
            ->latest('id')
            ->first();
    }

    public function open(Company $company, ?User $user, float $float): PosSession
    {
        $float = round($float, 2);
        if ($float < 0 || $float > 1_000_000) {
            throw new RuntimeException('Μη έγκυρο ποσό ρέστων ανοίγματος.');
        }

        return DB::transaction(function () use ($company, $user, $float): PosSession {
            // Serialise two «Άνοιγμα» clicks (two tabs): one open session per company.
            Company::query()->whereKey($company->getKey())->lockForUpdate()->first();
            if ($this->current($company) !== null) {
                throw new RuntimeException('Το ταμείο είναι ήδη ανοιχτό.');
            }

            return PosSession::query()->withoutGlobalScope(CompanyScope::class)->create([
                'company_id' => $company->getKey(),
                'opened_by' => $user?->getKey(),
                'opened_at' => now(),
                'opening_float' => $float,
            ]);
        });
    }

    /** Cash into (in) or out of (out) the drawer — e.g. extra change, a supplier paid in cash. */
    public function move(PosSession $session, ?User $user, string $direction, float $amount, string $reason): PosCashMovement
    {
        $amount = round($amount, 2);
        $reason = trim($reason);
        if (! in_array($direction, [PosCashMovement::IN, PosCashMovement::OUT], true)) {
            throw new RuntimeException('Μη έγκυρη κίνηση μετρητών.');
        }
        if ($amount <= 0 || $amount > 1_000_000) {
            throw new RuntimeException('Μη έγκυρο ποσό.');
        }
        if ($reason === '') {
            throw new RuntimeException('Γράψε την αιτία της κίνησης.');
        }

        return DB::transaction(function () use ($session, $user, $direction, $amount, $reason): PosCashMovement {
            $this->lockOpen($session);

            return PosCashMovement::query()->withoutGlobalScope(CompanyScope::class)->create([
                'company_id' => $session->company_id,
                'pos_session_id' => $session->getKey(),
                'user_id' => $user?->getKey(),
                'direction' => $direction,
                'amount' => $amount,
                'reason' => mb_substr($reason, 0, 255),
            ]);
        });
    }

    public function close(PosSession $session, ?User $user, float $counted, ?string $notes = null): PosSession
    {
        $counted = round($counted, 2);
        if ($counted < 0 || $counted > 10_000_000) {
            throw new RuntimeException('Μη έγκυρο ποσό καταμέτρησης.');
        }

        return DB::transaction(function () use ($session, $user, $counted, $notes): PosSession {
            $locked = $this->lockOpen($session);
            $report = $this->report($locked);

            $locked->forceFill([
                'closed_at' => now(),
                'closed_by' => $user?->getKey(),
                'expected_cash' => $report['expected_cash'],
                'counted_cash' => $counted,
                'closing_report' => $report,
                'notes' => filled($notes) ? trim((string) $notes) : null,
            ])->save();

            return $locked;
        });
    }

    /**
     * The session's figures: the FROZEN report of a closed session, the live one of
     * an open session.
     *
     * @return array{opening_float: float, sales_count: int, sales_total: float, refunds_count: int, refunds_total: float, net_total: float, by_method: list<array{method: string, cash: bool, sales_count: int, sales: float, refunds_count: int, refunds: float}>, vat: list<array{rate: float, net: float, vat: float, gross: float}>, cash_sales: float, cash_refunds: float, cash_in: float, cash_out: float, expected_cash: float, movements: list<array{at: string, direction: string, amount: float, reason: string, user: ?string}>}
     */
    public function report(PosSession $session): array
    {
        if (! $session->isOpen() && is_array($session->closing_report)) {
            return $session->closing_report;
        }

        $company = Company::query()->find($session->company_id);
        $docs = Invoice::query()->withoutGlobalScope(CompanyScope::class)
            ->where('company_id', $session->company_id)
            ->where('pos_session_id', $session->getKey())
            ->where('local_status', 'active')
            ->tap(fn ($q) => InvoiceScope::live($q))
            ->with(['paymentMethod', 'invoiceType', 'lines'])
            ->orderBy('id')
            ->get();

        $byMethod = [];
        $vat = [];
        $totals = ['sales_count' => 0, 'sales_total' => 0.0, 'refunds_count' => 0, 'refunds_total' => 0.0, 'cash_sales' => 0.0, 'cash_refunds' => 0.0];
        foreach ($docs as $doc) {
            $refund = (bool) $doc->invoiceType?->is_credit;
            $amount = (float) $doc->payableTotal();
            $method = $doc->paymentMethod;
            $cash = $method !== null && ((int) $method->mydata_payment_type === 3
                || (int) $method->getKey() === (int) $company?->pos_payment_method_id);
            $key = $method?->getKey() ?? 0;
            $byMethod[$key] ??= ['method' => (string) ($method?->description ?? '—'), 'cash' => $cash, 'sales_count' => 0, 'sales' => 0.0, 'refunds_count' => 0, 'refunds' => 0.0];

            if ($refund) {
                $byMethod[$key]['refunds_count']++;
                $byMethod[$key]['refunds'] += $amount;
                $totals['refunds_count']++;
                $totals['refunds_total'] += $amount;
                $totals['cash_refunds'] += $cash ? $amount : 0.0;
            } else {
                $byMethod[$key]['sales_count']++;
                $byMethod[$key]['sales'] += $amount;
                $totals['sales_count']++;
                $totals['sales_total'] += $amount;
                $totals['cash_sales'] += $cash ? $amount : 0.0;
            }

            // VAT per rate, net of the returns (a credit note subtracts).
            foreach (InvoiceVatBreakdown::for($doc)->rows as $row) {
                $rate = (string) round((float) $row['rate'], 2);
                $vat[$rate] ??= ['rate' => (float) $rate, 'net' => 0.0, 'vat' => 0.0, 'gross' => 0.0];
                $sign = $refund ? -1 : 1;
                $vat[$rate]['net'] += $sign * (float) $row['net'];
                $vat[$rate]['vat'] += $sign * (float) $row['vat'];
                $vat[$rate]['gross'] += $sign * ((float) $row['net'] + (float) $row['vat']);
            }
        }

        $movements = PosCashMovement::query()->withoutGlobalScope(CompanyScope::class)
            ->where('pos_session_id', $session->getKey())
            ->with('user')
            ->orderBy('id')
            ->get();
        $cashIn = (float) $movements->where('direction', PosCashMovement::IN)->sum('amount');
        $cashOut = (float) $movements->where('direction', PosCashMovement::OUT)->sum('amount');

        $float = (float) $session->opening_float;
        $r2 = fn (float $v): float => round($v, 2);

        return [
            'opening_float' => $r2($float),
            'sales_count' => $totals['sales_count'],
            'sales_total' => $r2($totals['sales_total']),
            'refunds_count' => $totals['refunds_count'],
            'refunds_total' => $r2($totals['refunds_total']),
            'net_total' => $r2($totals['sales_total'] - $totals['refunds_total']),
            'by_method' => array_values(array_map(fn (array $m) => array_merge($m, ['sales' => $r2($m['sales']), 'refunds' => $r2($m['refunds'])]), $byMethod)),
            'vat' => array_values(array_map(fn (array $v) => ['rate' => $v['rate'], 'net' => $r2($v['net']), 'vat' => $r2($v['vat']), 'gross' => $r2($v['gross'])], $vat)),
            'cash_sales' => $r2($totals['cash_sales']),
            'cash_refunds' => $r2($totals['cash_refunds']),
            'cash_in' => $r2($cashIn),
            'cash_out' => $r2($cashOut),
            'expected_cash' => $r2($float + $totals['cash_sales'] - $totals['cash_refunds'] + $cashIn - $cashOut),
            'movements' => $movements->map(fn (PosCashMovement $m) => [
                'at' => $m->created_at?->format('H:i') ?? '',
                'direction' => $m->direction,
                'amount' => (float) $m->amount,
                'reason' => (string) $m->reason,
                'user' => $m->user?->name,
            ])->values()->all(),
        ];
    }

    /**
     * The session, re-read under a row lock, if it is still open — the till's
     * documents lock it too while being attached, so «Κλείσιμο» never snapshots
     * a report while a sale is being rung into the same session.
     */
    public function lockOpen(PosSession $session): PosSession
    {
        $locked = PosSession::query()->withoutGlobalScope(CompanyScope::class)
            ->whereKey($session->getKey())
            ->lockForUpdate()
            ->first();
        if ($locked === null || ! $locked->isOpen()) {
            throw new RuntimeException('Το ταμείο έχει κλείσει — άνοιξε νέο ταμείο.');
        }

        return $locked;
    }
}
