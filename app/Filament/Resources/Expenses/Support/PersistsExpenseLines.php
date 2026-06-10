<?php

namespace App\Filament\Resources\Expenses\Support;

use App\Models\Expense;
use App\Models\Supplier;
use Illuminate\Support\Arr;

/**
 * Shared header/line persistence for the manual-expense Create/Edit pages.
 * Splits the form's flat `lines` repeater out of the header, recomputes the
 * header money totals from the lines (the receipt's figures — no VAT math
 * invented), and snapshots the supplier name/AFM. The caller stamps company_id
 * + line_number on each line (BelongsToCompany doesn't auto-fill them).
 */
trait PersistsExpenseLines
{
    /**
     * @param  array<string, mixed>  $data
     * @return array{0: array<string, mixed>, 1: list<array<string, mixed>>}  [header, lines]
     */
    protected function splitExpenseData(array $data, int $companyId): array
    {
        $lines = array_values($data['lines'] ?? []);
        $header = Arr::except($data, ['lines']);

        // Money totals = Σ of the line figures the operator transcribed.
        $header['net_total'] = round(collect($lines)->sum(fn ($l) => (float) ($l['net_value'] ?? 0)), 2);
        $header['vat_total'] = round(collect($lines)->sum(fn ($l) => (float) ($l['vat_amount'] ?? 0)), 2);
        $header['gross_total'] = round($header['net_total'] + $header['vat_total'], 2);

        // Supplier snapshot: prefer the picked supplier, else the typed name.
        if (! empty($header['supplier_id'])) {
            $supplier = Supplier::query()->where('company_id', $companyId)->find($header['supplier_id']);
            if ($supplier !== null) {
                $header['supplier_afm'] = $supplier->afm;
                $header['supplier_name'] = $supplier->name;
            }
        }

        return [$header, $lines];
    }

    /**
     * Replace the expense's lines with the submitted set, stamping company_id +
     * a 1-based line_number. Delete-and-recreate keeps it simple and correct for
     * the small line counts an expense doc carries.
     *
     * @param  list<array<string, mixed>>  $lines
     */
    protected function syncExpenseLines(Expense $expense, array $lines, int $companyId): void
    {
        $expense->lines()->delete();

        foreach ($lines as $i => $line) {
            $expense->lines()->create([
                'company_id' => $companyId,
                'line_number' => $i + 1,
                'item_descr' => $line['item_descr'] ?? null,
                'quantity' => $line['quantity'] ?? 1,
                'vat_category' => $line['vat_category'] ?? null,
                'net_value' => $line['net_value'] ?? 0,
                'vat_amount' => $line['vat_amount'] ?? 0,
            ]);
        }
    }
}
