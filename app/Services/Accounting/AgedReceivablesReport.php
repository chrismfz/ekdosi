<?php

namespace App\Services\Accounting;

use App\Enums\PaymentStatus;
use App\Models\Company;
use App\Models\Customer;
use App\Models\Invoice;
use App\Services\CustomerLedger\CustomerLedgerBuilder;

/**
 * Aggregate aged-receivables (ηλικίωση οφειλών) across all customers: how much
 * each customer owes, bucketed 0-30 / 31-60 / 61-90 / 90+ days. Read-only.
 *
 * Reuses CustomerLedgerBuilder::buildStatsBlock per customer so the ageing math
 * (FIFO settlement, credit notes, credit-term-only) is IDENTICAL to the Καρτέλα —
 * one customer's report row equals their Καρτέλα ageing. Candidates are narrowed
 * by the cached payment_status (the same money cache the dashboard reads), so we
 * only build the handful of customers that actually carry a balance.
 */
class AgedReceivablesReport
{
    public function build(Company $tenant): AgedReceivablesResult
    {
        $customerIds = Invoice::query()
            ->where('company_id', $tenant->getKey())
            ->whereNull('credited_invoice_id')
            ->whereNotNull('customer_id')
            ->whereIn('payment_status', [PaymentStatus::Unpaid->value, PaymentStatus::Partial->value])
            ->distinct()
            ->pluck('customer_id')
            ->all();

        if ($customerIds === []) {
            return new AgedReceivablesResult([]);
        }

        $builder = app(CustomerLedgerBuilder::class);
        $rows = [];

        $customers = Customer::query()
            ->where('company_id', $tenant->getKey())
            ->whereIn('id', $customerIds)
            ->get();

        foreach ($customers as $customer) {
            $block = $builder->buildStatsBlock($customer);
            $aging = $block['aging'];
            $total = round(
                $aging['bucket_0_30'] + $aging['bucket_31_60'] + $aging['bucket_61_90'] + $aging['bucket_90_plus'],
                2,
            );

            // FIFO ageing can net to ~0 (fully settled) even when the cache flagged
            // the customer — skip those so the report shows only real debtors.
            if ($total <= 0.005) {
                continue;
            }

            $rows[] = new AgedReceivablesRow(
                customerId: (int) $customer->getKey(),
                customerName: (string) $customer->name,
                afm: $customer->afm,
                b0_30: $aging['bucket_0_30'],
                b31_60: $aging['bucket_31_60'],
                b61_90: $aging['bucket_61_90'],
                b90plus: $aging['bucket_90_plus'],
                total: $total,
                oldestDays: $block['stats']['oldest_unpaid_days'] ?? null,
            );
        }

        // Biggest debtors first.
        usort($rows, fn (AgedReceivablesRow $a, AgedReceivablesRow $b) => $b->total <=> $a->total);

        return new AgedReceivablesResult($rows);
    }
}
