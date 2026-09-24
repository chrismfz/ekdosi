<?php

namespace App\Services\Accounting;

use App\Models\Company;
use App\Models\Invoice;
use App\Models\InvoiceType;
use App\Models\Scopes\CompanyScope;
use App\Support\Accounting\ItemLabelNormalizer;
use Illuminate\Support\Collection;

/**
 * «Αξία άτυπων» — docs/non-billable-services.md §2/§8.5: what the informal series
 * (ΕΣΩ/ΔΟΚ — our own services, friends, tests) delivered without charging, for a
 * year. The one place their value shows: every money/VAT total excludes them by
 * design (InvoiceScope::live()).
 *
 * Scope: ISSUED (active) documents of an informal series — not drafts, not
 * cancelled. One whose «Μετατροπή σε φορολογικό» is live AND issued (the fiscal
 * document is active — not a draft still waiting, not cancelled or fully credited)
 * was charged after all, so it is NOT counted as unbilled value — it is reported
 * separately. A conversion still in draft stays unbilled (marked in the list).
 *
 * Money: the document's own net/gross (header discount already applied). Per item,
 * the line's net/gross scaled by the header discount, so Σ(items) ≈ Σ(documents)
 * (±cent rounding). Item identity follows «Ισοζύγιο Ειδών» (product, else the
 * normalised description — renewals of the same package fold together).
 */
class InformalValue
{
    /**
     * @return array{
     *     year: int,
     *     documents: list<array{id: int, invcode: string, date: string, series: string, customer: string, net: float, vat: float, gross: float, converted_to: ?string}>,
     *     by_customer: list<array{label: string, docs: int, net: float, vat: float, gross: float}>,
     *     by_item: list<array{label: string, qty: float, net: float, gross: float}>,
     *     by_series: list<array{label: string, docs: int, net: float, gross: float}>,
     *     total_docs: int, total_net: float, total_vat: float, total_gross: float,
     *     converted_docs: int, converted_gross: float
     * }
     */
    public function build(Company $company, int $year, ?int $seriesId = null): array
    {
        $informalTypes = InvoiceType::query()->withoutGlobalScope(CompanyScope::class)->withTrashed()
            ->where('company_id', $company->getKey())
            ->where('is_informal', true)
            ->when($seriesId !== null, fn ($q) => $q->whereKey($seriesId))
            ->get()
            ->keyBy('id');

        $docs = $informalTypes->isEmpty() ? collect() : Invoice::query()
            ->withoutGlobalScope(CompanyScope::class)
            ->where('company_id', $company->getKey())
            ->whereIn('invoice_type_id', $informalTypes->keys())
            ->where('local_status', 'active')
            ->whereBetween('issued_at', [$year.'-01-01 00:00:00', $year.'-12-31 23:59:59'])
            ->with(['lines.product', 'customer'])
            ->orderBy('issued_at')
            ->orderBy('id')
            ->get();

        // The same «live» rule as Invoice::liveConversion() — one query for all.
        $liveBySource = $docs->isEmpty() ? collect() : Invoice::liveConversionsOf($docs->pluck('id')->all())
            ->orderBy('id')->get()->groupBy('converted_from_invoice_id');

        $documents = [];
        $unbilled = collect();
        $converted = collect();
        foreach ($docs as $doc) {
            // Charged once the live conversion is issued.
            $live = $liveBySource->get($doc->id)?->first();
            $charged = $live !== null && $live->local_status === 'active';
            $documents[] = [
                'id' => $doc->id,
                'invcode' => (string) $doc->invcode,
                'date' => $doc->issued_at?->format('d/m/Y') ?? '',
                'series' => (string) ($informalTypes[$doc->invoice_type_id]->code ?? ''),
                'customer' => $this->customerLabel($doc),
                'net' => (float) $doc->net_total,
                'vat' => round((float) $doc->gross_total - (float) $doc->net_total, 2),
                'gross' => (float) $doc->gross_total,
                'converted_to' => $live === null ? null : $live->invcode.($charged ? '' : ' (πρόχειρο)'),
            ];
            ($charged ? $converted : $unbilled)->push($doc);
        }

        return [
            'year' => $year,
            'documents' => $documents,
            'by_customer' => $this->byCustomer($unbilled),
            'by_item' => $this->byItem($unbilled),
            'by_series' => $this->bySeries($unbilled, $informalTypes),
            'total_docs' => $unbilled->count(),
            'total_net' => round((float) $unbilled->sum('net_total'), 2),
            'total_vat' => round((float) $unbilled->sum('gross_total') - (float) $unbilled->sum('net_total'), 2),
            'total_gross' => round((float) $unbilled->sum('gross_total'), 2),
            'converted_docs' => $converted->count(),
            'converted_gross' => round((float) $converted->sum('gross_total'), 2),
        ];
    }

    private function customerLabel(Invoice $doc): string
    {
        return (string) ($doc->customer?->name ?: ($doc->company_name ?: '(χωρίς πελάτη)'));
    }

    /** @param Collection<int, Invoice> $docs */
    private function byCustomer(Collection $docs): array
    {
        return $docs->groupBy(fn (Invoice $d) => $d->customer_id !== null ? 'c:'.$d->customer_id : 'n:'.$this->customerLabel($d))
            ->map(fn (Collection $g) => [
                'label' => $this->customerLabel($g->first()),
                'docs' => $g->count(),
                'net' => round((float) $g->sum('net_total'), 2),
                'vat' => round((float) $g->sum('gross_total') - (float) $g->sum('net_total'), 2),
                'gross' => round((float) $g->sum('gross_total'), 2),
            ])
            ->sortByDesc('gross')->values()->all();
    }

    /** @param Collection<int, Invoice> $docs */
    private function byItem(Collection $docs): array
    {
        $rows = [];
        foreach ($docs as $doc) {
            $factor = 1 - ((float) $doc->header_discount_percent) / 100;
            foreach ($doc->lines as $line) {
                if ($line->product_id !== null) {
                    $key = 'p:'.$line->product_id;
                    $label = (string) ($line->product?->description_short ?: $line->product?->description ?: $line->product_descr);
                } else {
                    $key = 'd:'.ItemLabelNormalizer::key((string) $line->product_descr);
                    $label = ItemLabelNormalizer::clean((string) $line->product_descr) ?: '(χωρίς περιγραφή)';
                }
                $rows[$key] ??= ['label' => $label, 'qty' => 0.0, 'net' => 0.0, 'gross' => 0.0];
                $rows[$key]['qty'] += (float) $line->qty;
                $rows[$key]['net'] += (float) $line->net_price * $factor;
                $rows[$key]['gross'] += (float) $line->gross_price * $factor;
            }
        }

        return collect($rows)
            ->map(fn (array $r) => ['label' => $r['label'], 'qty' => $r['qty'], 'net' => round($r['net'], 2), 'gross' => round($r['gross'], 2)])
            ->sortByDesc('gross')->values()->all();
    }

    /**
     * @param  Collection<int, Invoice>  $docs
     * @param  Collection<int, InvoiceType>  $types
     */
    private function bySeries(Collection $docs, Collection $types): array
    {
        return $docs->groupBy('invoice_type_id')
            ->map(fn (Collection $g, $typeId) => [
                'label' => ($types[$typeId]->code ?? '?').' — '.($types[$typeId]->name ?? ''),
                'docs' => $g->count(),
                'net' => round((float) $g->sum('net_total'), 2),
                'gross' => round((float) $g->sum('gross_total'), 2),
            ])
            ->sortByDesc('gross')->values()->all();
    }
}
