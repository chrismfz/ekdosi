<?php

namespace App\Support\MyData;

/**
 * Curated «typical fees/taxes» presets for the invoice form — a friendlier layer
 * over the raw amount + §8.x category fields. Each preset pins the right tax group
 * (which amount/category columns) + the AADE category code, and a rate (for the
 * percentage-based ones) so the amount can be auto-computed from the invoice net.
 * Flat presets (rate null) leave the amount for the operator.
 *
 * PREVIEW scope — a small representative set (stamp duty %, accommodation fee,
 * services withholding). Extend `all()` as real tenant needs surface.
 */
class CommonTaxPresets
{
    /** tax group → [amount column, category column] on `invoices`. */
    public const GROUPS = [
        'withhold' => ['withhold_amount', 'withhold_category', 'withhold_rate'],
        'fees' => ['fees_amount', 'fees_category', 'fees_rate'],
        'other_taxes' => ['other_taxes_amount', 'other_taxes_category', 'other_taxes_rate'],
        'stamp_duty' => ['stamp_duty_amount', 'stamp_duty_category', 'stamp_duty_rate'],
        'deductions' => ['deductions_amount', 'deductions_category', 'deductions_rate'],
    ];

    /**
     * @return array<string, array{label:string, group:string, category:int, rate:?float}>
     */
    public static function all(): array
    {
        return [
            'stamp_3_6' => ['label' => 'Χαρτόσημο 3,6%', 'group' => 'stamp_duty', 'category' => 3, 'rate' => 3.6],
            'stamp_2_4' => ['label' => 'Χαρτόσημο 2,4%', 'group' => 'stamp_duty', 'category' => 2, 'rate' => 2.4],
            'stamp_1_2' => ['label' => 'Χαρτόσημο 1,2%', 'group' => 'stamp_duty', 'category' => 1, 'rate' => 1.2],
            'withhold_20' => ['label' => 'Παρακράτηση 20% (αμοιβές συμβούλων)', 'group' => 'withhold', 'category' => 3, 'rate' => 20.0],
            'accommodation' => ['label' => 'Τέλος διαμονής παρεπιδημούντων (σταθερό ποσό)', 'group' => 'fees', 'category' => 18, 'rate' => null],
        ];
    }

    /** @return array<string,string> key => label, for a Filament Select. */
    public static function options(): array
    {
        return array_map(fn (array $p) => $p['label'], self::all());
    }

    /** @return array{label:string, group:string, category:int, rate:?float}|null */
    public static function find(?string $key): ?array
    {
        return self::all()[$key] ?? null;
    }

    /** [amountColumn, categoryColumn, rateColumn] for a preset's group. */
    public static function columnsFor(array $preset): array
    {
        return self::GROUPS[$preset['group']];
    }

    /**
     * Net base from the live lines-repeater state: Σ qty × price × (1 − line-disc%),
     * THEN the invoice-level header discount — matching the base the submitter files
     * (InvoiceVatBreakdown::totalNet, which applies the header discount too). Used to
     * auto-fill a %-based amount. NB: a convenience preview value computed at pick-
     * time — the authoritative net (per-line-rounded, grouped per VAT rate) is
     * recomputed server-side on save, so expect ±€0.01 on multi-line invoices.
     *
     * @param  array<int,array<string,mixed>>  $lines
     */
    public static function netFromLines(array $lines, float $headerDiscountPercent = 0): float
    {
        $net = 0.0;
        foreach ($lines as $line) {
            $qty = (float) ($line['qty'] ?? 0);
            $price = (float) ($line['price_per_item'] ?? 0);
            $discount = (float) ($line['discount'] ?? 0);
            $net += $qty * $price * (1 - $discount / 100);
        }

        return round($net * (1 - $headerDiscountPercent / 100), 2);
    }

    /** Computed amount for a preset given the net, or null for a flat (operator-entered) one. */
    public static function amountFor(array $preset, float $net): ?float
    {
        return $preset['rate'] === null ? null : round($net * $preset['rate'] / 100, 2);
    }
}
