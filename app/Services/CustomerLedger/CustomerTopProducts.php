<?php

namespace App\Services\CustomerLedger;

use App\Models\Company;
use App\Models\Customer;
use App\Models\InvoiceLine;
use App\Support\InvoiceScope;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * «Συχνά προϊόντα/υπηρεσίες» — top-N of what was sold, most-frequent first.
 *
 * Aggregates the invoice lines of LIVE sales invoices (not cancelled, not
 * AADE-cancelled, excluding credit notes and unissued drafts) into a top-N list:
 * what was bought, how often, total quantity + net spend, and when it last sold.
 * Lines without a linked product fall back to their free-text description so
 * manually-typed items still surface.
 *
 * Two entry points, same aggregation:
 *   - {@see for()} — one customer's Καρτέλα (all-time).
 *   - {@see forCompany()} — the whole tenant over a period (the AI «top_products»
 *     tool / company-wide view).
 * Read-only and tenant-safe: `for()` reaches lines through the customer's own
 * invoices; `forCompany()` scopes explicitly by `company_id`. Neither leaks across
 * tenants.
 */
class CustomerTopProducts
{
    /**
     * @return array<int, array{
     *     key: string,
     *     label: string,
     *     product_id: ?int,
     *     sku: ?string,
     *     times: int,
     *     qty: float,
     *     net: float,
     *     unit: ?string,
     *     last_at: ?string,
     * }>
     */
    public function for(Customer $customer, int $limit = 10): array
    {
        $rows = InvoiceLine::query()
            ->whereHas('invoice', function ($q) use ($customer): void {
                $q->where('customer_id', $customer->getKey());
                // Pure sales only — exclude credit notes so a return doesn't read
                // as "frequently bought" (MON-9: incl. standalone legacy is_credit ΠΙΣ).
                InvoiceScope::excludeCreditNotes($q);
                // MON-5: a draft's lines aren't «bought» yet — exclude unissued sale drafts.
                InvoiceScope::excludeUnissuedDrafts($q);
                InvoiceScope::live($q);
            })
            ->with(['product:id,sku,description_short,description', 'invoice:id,issued_at'])
            ->get();

        return $this->aggregate($rows, $limit);
    }

    /**
     * Company-wide top products/services over a period (optional bounds). Same
     * live-sales filter as {@see for()}, scoped explicitly by tenant.
     *
     * @return array<int, array{key: string, label: string, product_id: ?int, sku: ?string, times: int, qty: float, net: float, unit: ?string, last_at: ?string}>
     */
    public function forCompany(Company $tenant, ?Carbon $from = null, ?Carbon $to = null, int $limit = 10): array
    {
        $rows = InvoiceLine::query()
            ->whereHas('invoice', function ($q) use ($tenant, $from, $to): void {
                $q->where('company_id', $tenant->getKey());
                if ($from !== null) {
                    $q->where('issued_at', '>=', $from);
                }
                if ($to !== null) {
                    $q->where('issued_at', '<=', $to);
                }
                InvoiceScope::excludeCreditNotes($q);
                InvoiceScope::excludeUnissuedDrafts($q);
                InvoiceScope::live($q);
            })
            ->with(['product:id,sku,description_short,description', 'invoice:id,issued_at'])
            ->get();

        return $this->aggregate($rows, $limit);
    }

    /**
     * Bucket a set of invoice lines into the top-N most-frequent products/services.
     *
     * @param  Collection<int, InvoiceLine>  $rows
     * @return array<int, array{key: string, label: string, product_id: ?int, sku: ?string, times: int, qty: float, net: float, unit: ?string, last_at: ?string}>
     */
    private function aggregate(Collection $rows, int $limit): array
    {
        $buckets = [];

        foreach ($rows as $line) {
            $key = $line->product_id !== null
                ? 'p:'.$line->product_id
                : 'd:'.mb_strtolower(trim((string) $line->product_descr));

            // A line with neither a product nor a description carries no
            // identity to aggregate on — skip it.
            if ($key === 'd:') {
                continue;
            }

            $issuedAt = $line->invoice?->issued_at;

            if (! isset($buckets[$key])) {
                $buckets[$key] = [
                    'key' => $key,
                    'label' => $this->labelFor($line),
                    'product_id' => $line->product_id,
                    'sku' => $line->product?->sku,
                    'times' => 0,
                    'qty' => 0.0,
                    'net' => 0.0,
                    'unit' => $line->metric_unit ?: null,
                    'last_at' => null,
                ];
            }

            $buckets[$key]['times']++;
            $buckets[$key]['qty'] += (float) $line->qty;
            $buckets[$key]['net'] += (float) $line->net_price;

            if ($issuedAt instanceof Carbon) {
                $prev = $buckets[$key]['last_at'];
                if ($prev === null || $issuedAt->gt(Carbon::parse($prev))) {
                    $buckets[$key]['last_at'] = $issuedAt->toIso8601String();
                }
            }
        }

        // Most-bought first (by frequency, then net spend as the tie-break).
        usort($buckets, static function (array $a, array $b): int {
            return [$b['times'], $b['net']] <=> [$a['times'], $a['net']];
        });

        return array_slice(array_map(static function (array $row): array {
            $row['qty'] = round($row['qty'], 3);
            $row['net'] = round($row['net'], 2);

            return $row;
        }, $buckets), 0, $limit);
    }

    private function labelFor(InvoiceLine $line): string
    {
        $product = $line->product;

        if ($product !== null) {
            $name = $product->description_short ?: $product->description;
            if (filled($name)) {
                return (string) $name;
            }
        }

        $descr = trim((string) $line->product_descr);

        return $descr !== '' ? $descr : '—';
    }
}
