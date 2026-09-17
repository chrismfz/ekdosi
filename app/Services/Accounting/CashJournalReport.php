<?php

namespace App\Services\Accounting;

use App\Models\BankAccount;
use App\Models\Company;
use App\Models\PaymentMethod;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

/**
 * «Ταμειακό ημερολόγιο (Εισπράξεων–Πληρωμών)» (#10) — the tenant's cash movements
 * for a period, grouped by ACCOUNT (bank account / ταμείο) → PAYMENT METHOD, with
 * εισπράξεις (kind=payment, money IN) vs πληρωμές/επιστροφές (kind=refund, money
 * OUT), subtotals per account and a grand total (καθαρή ταμειακή ροή = in − out).
 * Read-only.
 *
 * Scope: the `payments` table only (customer-side receipts + refunds) — expenses /
 * supplier payments belong to the Βιβλίο Εσόδων-Εξόδων, not here. Tenant-scoped by
 * explicit `company_id`; soft-deleted rows excluded. The `amount` column is always a
 * positive magnitude — direction comes from `kind` (Payment::NET_AMOUNT_SQL).
 *
 * Cash date = COALESCE(pay_date, DATE(created_at)) so a payment with no explicit
 * pay_date still lands in a period (its recorded-at day) instead of vanishing.
 */
class CashJournalReport
{
    /**
     * @return array{
     *     period_label: string,
     *     accounts: list<array{account_id: ?int, account: string, methods: list<array{method: string, in: float, out: float, net: float, count: int}>, in: float, out: float, net: float, count: int}>,
     *     total_in: float, total_out: float, total_net: float, total_count: int
     * }
     */
    public function build(Company $tenant, CarbonInterface $start, CarbonInterface $end): array
    {
        $companyId = (int) $tenant->getKey();

        $from = $start->toDateString();
        $to = $end->toDateString();

        $rows = DB::table('payments')
            ->where('company_id', $companyId)
            ->whereNull('deleted_at')
            // A NULL-amount row (a legacy/ETL anomaly — the model guard forbids it on
            // new rows) is not a cash movement: exclude it so it doesn't inflate the
            // «Πλήθος» count with a €0.00 line.
            ->whereNotNull('amount')
            // Cash date = pay_date, falling back to the recorded-at day when it's null,
            // so no payment vanishes. Kept SARGABLE: the common (non-null) case is a
            // plain range on the indexed pay_date; only the rare null branch wraps
            // created_at in DATE().
            ->where(function ($q) use ($from, $to): void {
                $q->whereBetween('pay_date', [$from, $to])
                    ->orWhere(function ($q2) use ($from, $to): void {
                        $q2->whereNull('pay_date')->whereRaw('DATE(created_at) BETWEEN ? AND ?', [$from, $to]);
                    });
            })
            ->groupBy('bank_account_id', 'payment_method_id', 'kind')
            ->select('bank_account_id', 'payment_method_id', 'kind')
            ->selectRaw('SUM(amount) as sum_amount')
            ->selectRaw('COUNT(*) as cnt')
            ->get();

        // Label maps (id → display), withTrashed so a SOFT-deleted account/method
        // (both models soft-delete) still resolves its real name for historical
        // payments. A HARD-deleted bank account is a non-issue: the payments FK is
        // ON DELETE SET NULL, so those rows already fall into «Χωρίς λογαριασμό».
        $accountLabels = BankAccount::withTrashed()
            ->where('company_id', $companyId)
            ->get()
            ->mapWithKeys(fn (BankAccount $a): array => [(int) $a->getKey() => $a->label()])
            ->all();
        $methodLabels = PaymentMethod::withTrashed()
            ->where('company_id', $companyId)
            ->pluck('description', 'id')
            ->all();

        // Fold into account => method => {in,out,net,count}.
        $accounts = [];
        foreach ($rows as $r) {
            $accId = $r->bank_account_id !== null ? (int) $r->bank_account_id : 0;
            $methodId = $r->payment_method_id !== null ? (int) $r->payment_method_id : 0;
            $amount = (float) $r->sum_amount;
            $count = (int) $r->cnt;
            $isRefund = $r->kind === 'refund';

            $accounts[$accId] ??= ['methods' => []];
            $accounts[$accId]['methods'][$methodId] ??= ['in' => 0.0, 'out' => 0.0, 'count' => 0];

            if ($isRefund) {
                $accounts[$accId]['methods'][$methodId]['out'] += $amount;
            } else {
                $accounts[$accId]['methods'][$methodId]['in'] += $amount;
            }
            $accounts[$accId]['methods'][$methodId]['count'] += $count;
        }

        $out = [];
        $totalIn = 0.0;
        $totalOut = 0.0;
        $totalCount = 0;

        foreach ($accounts as $accId => $acc) {
            $methods = [];
            $accIn = 0.0;
            $accOut = 0.0;
            $accCount = 0;

            foreach ($acc['methods'] as $mId => $m) {
                $methods[] = [
                    'method' => $mId > 0 ? (string) ($methodLabels[$mId] ?: ('#'.$mId)) : '—',
                    'in' => round($m['in'], 2),
                    'out' => round($m['out'], 2),
                    'net' => round($m['in'] - $m['out'], 2),
                    'count' => $m['count'],
                ];
                $accIn += $m['in'];
                $accOut += $m['out'];
                $accCount += $m['count'];
            }

            // Methods: biggest gross movement first; label as a deterministic tiebreak.
            // Round the gross before comparing so equal-gross rows can't diverge on
            // IEEE-754 noise and skip the label tiebreak.
            usort($methods, fn (array $a, array $b): int => [round($b['in'] + $b['out'], 2), $a['method']] <=> [round($a['in'] + $a['out'], 2), $b['method']]);

            $out[] = [
                'account_id' => $accId > 0 ? $accId : null,
                'account' => $accId > 0 ? (string) ($accountLabels[$accId] ?: ('#'.$accId)) : 'Χωρίς λογαριασμό',
                'methods' => $methods,
                'in' => round($accIn, 2),
                'out' => round($accOut, 2),
                'net' => round($accIn - $accOut, 2),
                'count' => $accCount,
            ];

            $totalIn += $accIn;
            $totalOut += $accOut;
            $totalCount += $accCount;
        }

        // Accounts: biggest gross movement first; the «Χωρίς λογαριασμό» bucket sinks
        // last, then account label as a deterministic tiebreak.
        usort($out, function (array $a, array $b): int {
            if (($a['account_id'] === null) !== ($b['account_id'] === null)) {
                return $a['account_id'] === null ? 1 : -1;
            }

            return [round($b['in'] + $b['out'], 2), $a['account']] <=> [round($a['in'] + $a['out'], 2), $b['account']];
        });

        return [
            'period_label' => $start->format('d/m/Y').' – '.$end->format('d/m/Y'),
            'accounts' => $out,
            'total_in' => round($totalIn, 2),
            'total_out' => round($totalOut, 2),
            'total_net' => round($totalIn - $totalOut, 2),
            'total_count' => $totalCount,
        ];
    }
}
