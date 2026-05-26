<?php

namespace App\Services;

use App\Models\Invoice;
use Illuminate\Support\Collection;

/**
 * Per-VAT-rate breakdown for an invoice. Port of the legacy stored
 * procedure CALCULATE_VAT_FOR_INVOICE (legacy/ekdosi-schema.sql:490):
 *
 *   SELECT VATPERCENT,
 *          SUM((PRICEWVAT - PRICE) - (PRICEWVAT - PRICE) * (INVOICE.DISCOUNT / 100))
 *   FROM INVLINES JOIN INVOICE ...
 *   GROUP BY VATPERCENT
 *
 * Translated:
 *   For each distinct VAT rate appearing on the invoice's lines:
 *     line_vat       = sum of (gross_price - net_price) for lines at that rate
 *     adjusted_vat   = line_vat - line_vat * (header_discount_percent / 100)
 *   That `adjusted_vat` is what we feed into the AADE myDATA payload's
 *   `taxesTotals` element per VAT rate.
 *
 * NOTE on rounding: the legacy stored proc rounds at the GROUP BY
 * aggregate level (one round() per rate). We mirror that exactly —
 * sum first, apply header discount, then round to 2dp. NEVER round
 * per-line then sum — that would lose €0.01 vs the legacy filed
 * values and the parallel-run golden test would fail.
 *
 * Consumed by:
 *   - MyDataSubmitter when building the SendInvoices payload (per-rate
 *     entries in TaxesTotals)
 *   - Future PDF rendering (the per-rate footer table)
 *   - Operator-facing "VAT breakdown" infolist section (not yet built)
 */
class InvoiceVatBreakdown
{
    /**
     * @param  list<array{rate: float, net: float, vat: float}>  $rows
     */
    public function __construct(
        public readonly array $rows,
    ) {}

    public static function for(Invoice $invoice): self
    {
        $invoice->loadMissing('lines');

        // Header discount is a percent 0..100 per CLAUDE.md schema
        // convention. Refuse pathological values: 100% would zero all
        // totals (legal as a promo but operationally weird), >100%
        // produces negative VAT (silently files a credit-like
        // payload). 100 itself is rejected because realistic 100%
        // discounts use a credit invoice, not a header discount.
        $hd = (float) $invoice->header_discount_percent;
        if ($hd < 0 || $hd >= 100) {
            throw new \RuntimeException(
                "Invoice {$invoice->invcode}: header_discount_percent={$hd} is outside the valid range 0..<100. ".
                'Edit on the InvoiceResource form; if a 100%-discount is genuinely needed, issue a credit invoice instead.'
            );
        }

        $discountFactor = 1 - ($hd / 100);

        $rows = $invoice->lines
            ->groupBy(fn ($line) => (string) $line->vat_percent)
            ->map(function (Collection $lines, $rateKey) use ($discountFactor) {
                // Sum BEFORE rounding — round only at the aggregate level
                // to match legacy CALCULATE_VAT_FOR_INVOICE semantics.
                $rawNet = $lines->sum(fn ($l) => (float) $l->net_price);
                $rawVat = $lines->sum(fn ($l) => (float) ($l->gross_price - $l->net_price));

                return [
                    'rate' => (float) $rateKey,
                    'net' => round($rawNet * $discountFactor, 2),
                    'vat' => round($rawVat * $discountFactor, 2),
                ];
            })
            ->sortBy('rate')
            ->values()
            ->all();

        return new self($rows);
    }

    public function totalNet(): float
    {
        return round(array_sum(array_column($this->rows, 'net')), 2);
    }

    public function totalVat(): float
    {
        return round(array_sum(array_column($this->rows, 'vat')), 2);
    }

    public function totalGross(): float
    {
        return round($this->totalNet() + $this->totalVat(), 2);
    }

    /**
     * Lookup the VAT amount for a specific rate (returns 0.0 if the
     * rate isn't present on this invoice). Used by the myDATA payload
     * builder, which needs to address per-rate amounts directly.
     */
    public function vatAtRate(float $rate): float
    {
        foreach ($this->rows as $row) {
            if (abs($row['rate'] - $rate) < 0.001) {
                return $row['vat'];
            }
        }
        return 0.0;
    }
}
