<?php

namespace App\Services\Dashboard;

use App\Models\Company;
use App\Support\MyData\Codes;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

/**
 * ΦΠΑ εκροών − εισροών report (Έξοδα phase, E6): the "πόσο ΦΠΑ θα χρωστάμε"
 * view. Combines the OUTPUT side (our invoices — reuses DashboardMetrics so the
 * live-scope + credit-note + cancelled rules stay in ONE place) with the INPUT
 * side (local expenses) into a per-period VatPeriodSummary.
 *
 * Tenant-scoped explicitly by company_id on every query (Expense/Invoice have
 * no global scope — see CLAUDE.md). Aggregates in SQL, never loads rows.
 *
 * LOCAL only — cross-checking against AADE's authoritative RequestVatInfo is a
 * deferred follow-up (surface drift, don't silently trust one side).
 */
class VatPeriodReport
{
    public function __construct(private readonly Company $tenant) {}

    public function forPeriod(CarbonInterface $start, CarbonInterface $end, ?string $label = null): VatPeriodSummary
    {
        // OUTPUT (εκροών): sales NET of credit notes (MON-1/MON-2). income()
        // only EXCLUDES credit notes (correct for gross turnover, wrong for VAT
        // liability — it over-declared output VAT); outputForVat() SUBTRACTS
        // them, matching LedgerBook + the Καρτέλα.
        $output = (new DashboardMetrics($this->tenant))->outputForVat($start, $end);

        // INPUT (εισροών): local expenses in the window, excluding AADE-cancelled.
        $input = $this->expenseInput($start, $end);

        return new VatPeriodSummary(
            label: $label ?? ($start->format('d/m/Y').' – '.$end->format('d/m/Y')),
            outputNet: $output->net,
            outputVat: $output->vat,
            outputGross: $output->gross,
            outputCount: $output->count,
            inputNet: (float) $input->net,
            inputVat: (float) $input->vat,
            inputGross: (float) $input->gross,
            inputCount: (int) $input->cnt,
        );
    }

    /**
     * The three months of the quarter containing $ref, oldest first, each as
     * its own VatPeriodSummary (for the per-month breakdown the operator asked
     * for). Months beyond "now" are still returned (zeros) for a stable layout.
     *
     * @return list<VatPeriodSummary>
     */
    public function monthsOfQuarter(CarbonInterface $ref): array
    {
        $cursor = $ref->copy()->startOfQuarter();
        $out = [];

        for ($i = 0; $i < 3; $i++) {
            $monthStart = $cursor->copy()->startOfMonth();
            $monthEnd = $cursor->copy()->endOfMonth();
            $out[] = $this->forPeriod($monthStart, $monthEnd, $cursor->format('m/Y'));
            $cursor->addMonthNoOverflow();
        }

        return $out;
    }

    /**
     * Σ net/vat/gross/count of this tenant's expenses issued in [$start, $end],
     * excluding AADE-cancelled docs (mydata_state='CANCELLED'; null/VALID stay).
     * issue_date is a DATE column → compare on date strings.
     *
     * Credit-note expense types (13.31 / 14.31 — self-declared πιστωτικά that
     * the importer lands here with POSITIVE totals) SUBTRACT: a supplier's
     * πιστωτικό reduces deductible input VAT, so summing it positively would
     * overstate it. The sign is driven by Codes::CREDIT_NOTE_TYPES so the
     * "this type subtracts" rule lives in ONE place (same authority the myDATA
     * VAT-picture aggregator uses).
     */
    private function expenseInput(CarbonInterface $start, CarbonInterface $end): object
    {
        $credit = Codes::CREDIT_NOTE_TYPES;
        $in = implode(',', array_fill(0, count($credit), '?'));
        // -1 for a credit-note invoice_type, +1 otherwise.
        $sign = "CASE WHEN invoice_type IN ($in) THEN -1 ELSE 1 END";

        $row = DB::table('expenses')
            ->where('company_id', $this->tenant->getKey())
            ->whereNull('deleted_at')
            ->whereBetween('issue_date', [$start->toDateString(), $end->toDateString()])
            ->where(fn ($q) => $q
                ->whereNull('mydata_state')
                ->orWhere('mydata_state', '!=', 'CANCELLED'))
            ->selectRaw(
                "COALESCE(SUM($sign * net_total), 0) net, "
                ."COALESCE(SUM($sign * vat_total), 0) vat, "
                ."COALESCE(SUM($sign * gross_total), 0) gross, "
                .'COUNT(*) cnt',
                [...$credit, ...$credit, ...$credit],
            )
            ->first();

        return (object) [
            'net' => round((float) ($row->net ?? 0), 2),
            'vat' => round((float) ($row->vat ?? 0), 2),
            'gross' => round((float) ($row->gross ?? 0), 2),
            'cnt' => (int) ($row->cnt ?? 0),
        ];
    }
}
