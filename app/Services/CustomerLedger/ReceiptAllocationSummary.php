<?php

namespace App\Services\CustomerLedger;

/**
 * Φ3 — renders a one-line allocation summary for a grouped
 * «έμβασμα/είσπραξη» ledger row, used by the CSV + PDF statements so the
 * collapsed receipt still shows what it settled WITHOUT exploding into
 * sub-rows. Mirrors the per-renderer money formatting (the caller passes
 * its own formatter so el-GR CSV vs Money::eur PDF stay consistent).
 *
 * Example: «Έμβασμα ΕΙΣ-… (ΤΙΜ1: 1.000,00 · ΤΙΜ2: 200,00 · Πίστωση: 0,00)»
 */
class ReceiptAllocationSummary
{
    /**
     * @param  list<array{label: string, amount: float, invoice_id: ?int, invcode: ?string}>  $allocations
     * @param  callable(float): string  $fmt
     */
    public static function describe(string $reference, array $allocations, callable $fmt): string
    {
        if ($allocations === []) {
            return $reference;
        }

        $parts = [];
        foreach ($allocations as $a) {
            $label = ! empty($a['invoice_id'])
                ? (string) ($a['invcode'] ?? $a['label'])
                : 'Πίστωση';
            $parts[] = $label.': '.$fmt((float) $a['amount']);
        }

        return $reference.' ('.implode(' · ', $parts).')';
    }
}
