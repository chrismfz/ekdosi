<?php

namespace App\Services\CustomerLedger;

use App\Models\Customer;
use App\Models\InvoiceLine;
use App\Support\InvoiceScope;
use Illuminate\Support\Carbon;

/**
 * «Συχνά προϊόντα/υπηρεσίες» for a customer's Καρτέλα.
 *
 * Aggregates the invoice lines of a customer's LIVE sales invoices (not
 * cancelled, not AADE-cancelled, excluding credit notes) into a top-N list:
 * what they buy, how often, total quantity + net spend, and when they last
 * bought it. Lines without a linked product fall back to their free-text
 * description so manually-typed items still surface.
 *
 * Read-only and tenant-safe: every line is reached through the customer's own
 * invoices, so it never leaks across tenants. Cheap and bounded per customer.
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
                $q->where('customer_id', $customer->getKey())
                    // Pure sales only — exclude credit notes so a return doesn't
                    // read as "frequently bought".
                    ->whereNull('credited_invoice_id');
                InvoiceScope::live($q);
            })
            ->with(['product:id,sku,description_short,description', 'invoice:id,issued_at'])
            ->get();

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
