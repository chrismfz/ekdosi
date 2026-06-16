<?php

namespace App\Services\MyData;

use App\Models\Expense;
use App\Models\ExpenseClassificationRule;
use Illuminate\Support\Collection;

/**
 * Applies the auto-classification rules (#5) to inbound expenses: finds the first
 * matching ExpenseClassificationRule for an expense's supplier (+ optional myDATA
 * type) and stamps its document-level classification (E3 type + category2_x),
 * moving it to `classification_state = 'classified'`. Used on import (when the
 * supplier's doc carried no classification of its own) and from the «Εφαρμογή
 * κανόνων» bulk action. Never touches an already-submitted classification.
 */
class ExpenseClassifier
{
    /**
     * Classify one expense from the rules. Returns true if a rule matched and was
     * applied. No-op (false) when already submitted, missing an ΑΦΜ, or no match.
     */
    public function classify(Expense $expense): bool
    {
        if ($expense->classification_state === 'submitted' || blank($expense->supplier_afm)) {
            return false;
        }

        $rule = $this->matchRule($expense);
        if ($rule === null) {
            return false;
        }

        $expense->forceFill([
            'classification_type' => $rule->classification_type,
            'classification_category' => $rule->classification_category,
            'classification_state' => 'classified',
        ])->save();

        return true;
    }

    /**
     * Apply rules to a set of expenses; returns how many were newly classified.
     *
     * @param  Collection<int, Expense>|iterable<Expense>  $expenses
     */
    public function classifyMany(iterable $expenses): int
    {
        $count = 0;
        foreach ($expenses as $expense) {
            $count += $this->classify($expense) ? 1 : 0;
        }

        return $count;
    }

    /**
     * The first rule that matches the expense's supplier ΑΦΜ, preferring a
     * type-specific rule over the generic (null invoice_type) one, then higher
     * priority. `invoice_type IS NULL` sorts as 1 (after 0), so specific wins.
     */
    public function matchRule(Expense $expense): ?ExpenseClassificationRule
    {
        if (blank($expense->supplier_afm)) {
            return null;
        }

        return ExpenseClassificationRule::query()
            ->where('company_id', $expense->company_id)
            ->where('is_active', true)
            ->where('supplier_afm', $expense->supplier_afm)
            ->where(fn ($q) => $q->whereNull('invoice_type')->orWhere('invoice_type', $expense->invoice_type))
            ->orderByRaw('invoice_type IS NULL')
            ->orderByDesc('priority')
            ->orderByDesc('id')
            ->first();
    }
}
