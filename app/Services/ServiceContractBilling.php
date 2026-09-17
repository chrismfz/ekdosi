<?php

namespace App\Services;

use App\Models\Activity;
use App\Models\Invoice;
use App\Models\ServiceContract;
use App\Support\InvoiceScope;
use App\Support\Money;
use Illuminate\Support\Carbon;

/**
 * Per-contract billing analytics for a ServiceContract: how many times it was
 * billed, the net revenue it produced (turnover convention), the first/last
 * billed dates, the pending-draft pipeline, and its list-price change timeline.
 * Read-only.
 *
 * Explicitly scoped by the contract's company_id (never the ambient
 * CompanyScope), so it's correct from CLI/queue/tests AND reusable as-is by a
 * future /user portal card without re-deriving the money rules. The "live" +
 * credit-note + unissued-draft predicates come from {@see InvoiceScope} — the
 * single home for those semantics, so these figures reconcile with turnover /
 * the Καρτέλα by construction.
 */
class ServiceContractBilling
{
    /** @var list<int>|null memoised sale-renewal ids (live, issued, non-credit). */
    private ?array $saleIds = null;

    /** @var array{net: float, gross: float}|null memoised credit-note totals. */
    private ?array $creditTotals = null;

    /** @var array{0: ?Carbon, 1: ?Carbon}|null memoised billed-date range. */
    private ?array $billedRange = null;

    public function __construct(private readonly ServiceContract $contract) {}

    /** How many times this contract was actually billed (live, issued sales). */
    public function billedCount(): int
    {
        return count($this->saleIds());
    }

    /**
     * Net revenue produced (turnover convention): Σ net_total of the contract's
     * live issued SALE renewals MINUS Σ net_total of live credit notes issued
     * against them. Credit notes carry no service_contract_id (IssueCreditNote
     * doesn't copy it), so they are attributed via credited_invoice_id ∈ sales —
     * which also catches a credit filed on a renewal even though the credit note
     * itself isn't linked to the contract.
     */
    public function netRevenue(): float
    {
        $ids = $this->saleIds();
        if ($ids === []) {
            return 0.0;
        }

        $sales = (float) Invoice::query()
            ->where('company_id', $this->contract->company_id)
            ->whereIn('id', $ids)
            ->sum('net_total');

        return round($sales - $this->creditTotals()['net'], 2);
    }

    /**
     * Gross billed net of credits (Σ gross_total of the live issued sales MINUS
     * the gross of the credit notes issued against them) — kept symmetric with
     * netRevenue() so the two tiles never disagree over a reversed renewal.
     */
    public function grossBilled(): float
    {
        $ids = $this->saleIds();
        if ($ids === []) {
            return 0.0;
        }

        $sales = (float) Invoice::query()
            ->where('company_id', $this->contract->company_id)
            ->whereIn('id', $ids)
            ->sum('gross_total');

        return round($sales - $this->creditTotals()['gross'], 2);
    }

    /** Earliest billed date among the live issued sales (null = never billed). */
    public function firstBilledAt(): ?Carbon
    {
        return $this->billedRange()[0];
    }

    /** Latest billed date among the live issued sales (null = never billed). */
    public function lastBilledAt(): ?Carbon
    {
        return $this->billedRange()[1];
    }

    /**
     * Unissued draft renewals still awaiting the operator (staged but not issued)
     * — the pipeline figure, deliberately NOT counted as revenue.
     */
    public function pendingDraftCount(): int
    {
        $query = Invoice::query()
            ->where('company_id', $this->contract->company_id)
            ->where('service_contract_id', $this->contract->id);
        InvoiceScope::onlyUnissuedDrafts($query);

        return $query->count();
    }

    /**
     * List-price change timeline from the audit log: each time the contract's
     * `amount` changed, "€old → €new · date · causer", newest first. The data is
     * already captured by TracksActivity (amount ∈ loggedAttributes), so this is
     * a pure read — no new storage.
     *
     * @return list<string>
     */
    public function priceHistory(): array
    {
        return $this->contract->activitiesAsSubject()
            ->with('causer')
            ->orderByDesc('created_at')
            ->orderByDesc('id') // deterministic tiebreak for same-second edits
            ->get()
            ->map(function (Activity $activity): ?string {
                $changes = $activity->attribute_changes;
                $new = (array) ($changes['attributes'] ?? []);
                $old = (array) ($changes['old'] ?? []);
                // Only actual price CHANGES (an edit carrying a previous value).
                // The 'created' event has amount but no `old` — that's the initial
                // list price (already shown as «Ποσό»), not a change, so skip it:
                // a never-edited contract then reads as an empty timeline, not a
                // misleading one-line "— → X".
                if (! array_key_exists('amount', $new) || ! array_key_exists('amount', $old)) {
                    return null;
                }
                $when = optional($activity->created_at)->format('d/m/Y');
                $who = $activity->causer?->name ?? 'Σύστημα';

                return Money::eur($old['amount']).' → '.Money::eur($new['amount'])." · {$when} · {$who}";
            })
            ->filter()
            ->values()
            ->all();
    }

    /**
     * The live, issued SALE renewals of this contract: not cancelled / AADE-
     * cancelled, not an unissued draft, not a credit note. Memoised per instance;
     * the count + revenue + date range all build on it. Explicit company_id scope.
     *
     * @return list<int>
     */
    private function saleIds(): array
    {
        if ($this->saleIds !== null) {
            return $this->saleIds;
        }

        $query = Invoice::query()
            ->where('company_id', $this->contract->company_id)
            ->where('service_contract_id', $this->contract->id);
        InvoiceScope::live($query);
        InvoiceScope::excludeUnissuedDrafts($query);
        InvoiceScope::excludeCreditNotes($query);

        return $this->saleIds = $query->pluck('id')->map(fn ($id): int => (int) $id)->all();
    }

    /**
     * Σ net_total + Σ gross_total of the LIVE credit notes issued against this
     * contract's sales (correlated via credited_invoice_id). Memoised — used by
     * both netRevenue() and grossBilled() so they net credits symmetrically.
     *
     * @return array{net: float, gross: float}
     */
    private function creditTotals(): array
    {
        if ($this->creditTotals !== null) {
            return $this->creditTotals;
        }

        $ids = $this->saleIds();
        if ($ids === []) {
            return $this->creditTotals = ['net' => 0.0, 'gross' => 0.0];
        }

        $query = Invoice::query()
            ->where('company_id', $this->contract->company_id)
            ->whereIn('credited_invoice_id', $ids);
        InvoiceScope::live($query);
        $row = $query->selectRaw('COALESCE(SUM(net_total), 0) as net, COALESCE(SUM(gross_total), 0) as gross')->first();

        return $this->creditTotals = [
            'net' => (float) ($row?->net ?? 0),
            'gross' => (float) ($row?->gross ?? 0),
        ];
    }

    /**
     * MIN/MAX issued_at over the live issued sales, in one query. Memoised so
     * firstBilledAt() + lastBilledAt() share a single aggregate query.
     *
     * @return array{0: ?Carbon, 1: ?Carbon}
     */
    private function billedRange(): array
    {
        if ($this->billedRange !== null) {
            return $this->billedRange;
        }

        $ids = $this->saleIds();
        if ($ids === []) {
            return $this->billedRange = [null, null];
        }

        $row = Invoice::query()
            ->where('company_id', $this->contract->company_id)
            ->whereIn('id', $ids)
            ->selectRaw('MIN(issued_at) as first_at, MAX(issued_at) as last_at')
            ->first();

        return $this->billedRange = [
            $row?->first_at ? Carbon::parse($row->first_at) : null,
            $row?->last_at ? Carbon::parse($row->last_at) : null,
        ];
    }
}
