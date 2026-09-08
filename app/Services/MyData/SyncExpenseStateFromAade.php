<?php

namespace App\Services\MyData;

use App\Models\Expense;
use App\Models\ExpenseMark;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Apply AADE's live state to a local expense — the operator-confirmed counterpart
 * to ExpenseReconciler (which only REPORTS a stateMismatch, deliberately never
 * auto-touching mydata_state). The expense-side twin of SyncInvoiceStateFromAade.
 *
 * A supplier can CANCEL a document they filed against us; that cancellation is
 * part of the authoritative inbound state (RequestDocs returns it), but
 * ExpenseImporter skips any existing MARK — so without this an already-imported
 * VALID expense stays VALID forever after the supplier cancels it (MYD-014).
 *
 * Expenses carry no local_status and no WHMCS write-back (unlike invoices), so
 * this only moves mydata_state (+ cancelled_by_mark) and writes a forensic
 * ExpenseMark STATE_SYNC row (from→to, no XML — there's no AADE request here,
 * we're recording our reconciliation decision). Once CANCELLED, LedgerBook /
 * VatPeriodReport already exclude the expense from the books.
 */
class SyncExpenseStateFromAade
{
    /**
     * @param  string  $aadeState  the live myDATA state ('VALID' | 'CANCELLED')
     * @return array{changed: bool, from: ?string, to: string}
     */
    public function sync(Expense $expense, string $aadeState, ?string $cancelledByMark = null): array
    {
        $aadeState = strtoupper(trim($aadeState));

        // Only the two terminal states are syncable — a blank/unknown value must
        // never silently wipe ours.
        if (! in_array($aadeState, ['VALID', 'CANCELLED'], true)) {
            throw new RuntimeException("Μη αναμενόμενη κατάσταση ΑΑΔΕ: «{$aadeState}».");
        }

        // A cancellation is a legal state change — require its evidence (the AADE
        // cancellation MARK). Never record CANCELLED with a null/blank mark, or the
        // audit trail can't say WHAT cancelled the document (MYD-014 review).
        if ($aadeState === 'CANCELLED' && ($cancelledByMark === null || trim($cancelledByMark) === '')) {
            throw new RuntimeException(
                "Αδύνατη η καταχώριση ακύρωσης για το έξοδο (ΜΑΡΚ {$expense->mydata_mark}) "
                .'χωρίς MARK ακύρωσης από την ΑΑΔΕ.'
            );
        }

        $fromState = $expense->mydata_state;
        // cancelled_by_mark only belongs on a CANCELLED expense; clear it on VALID.
        $toCancelledBy = $aadeState === 'CANCELLED' ? $cancelledByMark : null;

        if ($fromState === $aadeState && $expense->cancelled_by_mark === $toCancelledBy) {
            return ['changed' => false, 'from' => $fromState, 'to' => $aadeState];
        }

        DB::transaction(function () use ($expense, $aadeState, $toCancelledBy, $fromState): void {
            $expense->forceFill([
                'mydata_state' => $aadeState,
                'cancelled_by_mark' => $toCancelledBy,
            ])->save();

            ExpenseMark::create([
                'company_id' => $expense->company_id,
                'expense_id' => $expense->id,
                'mark' => $expense->mydata_mark,
                'mydata_action' => 'STATE_SYNC',
                'request' => "Συγχρονισμός κατάστασης από ΑΑΔΕ: {$fromState} → {$aadeState}"
                    .($toCancelledBy !== null ? " (ακύρωση με ΜΑΡΚ {$toCancelledBy})" : ''),
                'response' => null,
                'mark_date' => now()->toDateString(),
                'mark_time' => now()->toTimeString(),
            ]);
        });

        Log::info('myDATA expense state synced from AADE', [
            'company_id' => $expense->company_id,
            'expense_id' => $expense->id,
            'mark' => $expense->mydata_mark,
            'from' => $fromState,
            'to' => $aadeState,
        ]);

        return ['changed' => true, 'from' => $fromState, 'to' => $aadeState];
    }
}
