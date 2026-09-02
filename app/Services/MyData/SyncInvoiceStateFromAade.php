<?php

namespace App\Services\MyData;

use App\Models\Invoice;
use App\Models\MyDataMark;
use App\Services\Whmcs\WhmcsWritebackService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Apply AADE's live state to a local invoice — the explicit, operator-confirmed
 * counterpart to EnrichInvoiceFromAade (which only REPORTS the divergence,
 * deliberately never auto-touching `mydata_state`).
 *
 * AADE is the source of truth, so this is genuinely 2-way:
 *   - AADE CANCELLED → local cancelled (e.g. the operator cancelled the doc
 *     straight from the myDATA portal; we mirror it back).
 *   - AADE VALID    → local VALID, and a local_status that was wrongly
 *     `cancelled` is un-cancelled to `active` (a VALID-at-AADE doc cannot
 *     legitimately read as locally cancelled — that's the whole point).
 *
 * Writes a STATE_SYNC forensic audit row (from→to, no XML — there's no AADE
 * request here, we're recording our reconciliation decision) and, when the
 * result is CANCELLED, reflects it on the WHMCS side (best-effort, no-op for
 * non-WHMCS invoices) exactly like the lifecycle cancel choke-point.
 */
class SyncInvoiceStateFromAade
{
    /**
     * @param  string  $aadeState  the live myDATA state ('VALID' | 'CANCELLED')
     * @param  string|null  $cancelledByMark  AADE's MARK for the CANCELLATION act,
     *                                        when it named one — the evidence of WHICH cancellation produced the
     *                                        terminal state (MYD-023). Recorded on the STATE_SYNC row, exactly as
     *                                        the direct/provider cancel paths record theirs. Adopting a
     *                                        cancellation without it used to leave a terminal state the database
     *                                        could not account for.
     * @return array{changed: bool, from: ?string, to: string, local_status: ?string}
     */
    public function sync(Invoice $invoice, string $aadeState, ?string $cancelledByMark = null): array
    {
        $aadeState = strtoupper(trim($aadeState));

        // Only the two terminal myDATA states are syncable. A blank/unknown
        // value (e.g. AADE didn't return a state) must never silently wipe ours.
        if (! in_array($aadeState, ['VALID', 'CANCELLED'], true)) {
            throw new RuntimeException("Μη αναμενόμενη κατάσταση ΑΑΔΕ: «{$aadeState}».");
        }

        // The cancellation MARK only means anything on a CANCELLED result, and it
        // is deliberately NOT required: unlike the expense twin, this is the one
        // route that recovers an invoice whose cancellation we learned about
        // late, and refusing the sync over missing evidence would strand exactly
        // the document it exists to repair.
        //
        // SHAPE-CHECKED, though. The only caller reaches this from a public
        // Livewire property, which is client-writable: without this an arbitrary
        // string would be written into the legal audit trail as «AADE's
        // cancellation MARK» — fabricated evidence — and anything over 40 chars
        // would abort the sync on a column-length error instead. A MARK is a
        // numeric AADE identifier; anything else is not one, so it is recorded as
        // «no evidence» rather than as evidence of something we cannot vouch for.
        $cancelledByMark = $aadeState === 'CANCELLED' ? self::cleanMark($cancelledByMark) : null;

        $fromState = $invoice->mydata_state;
        $fromLocal = $invoice->local_status;

        $toLocal = match ($aadeState) {
            'CANCELLED' => 'cancelled',
            // Un-cancel a wrongly-cancelled doc; otherwise leave business intent.
            'VALID' => $fromLocal === 'cancelled' ? 'active' : $fromLocal,
        };

        // No-op only when BOTH columns already match the target — the job is
        // "make local match AADE", not just the mydata_state (so a VALID-at-AADE
        // doc that's still wrongly local-cancelled is fixed, not skipped).
        //
        // A re-sync that would ONLY add a late-arriving cancellation MARK stops
        // here and records nothing. Unreachable from the panel (the action is
        // offered only on a state divergence) and it writes no audit row today
        // either, so it stays a no-op rather than a reason to widen this.
        if ($fromState === $aadeState && $fromLocal === $toLocal) {
            return ['changed' => false, 'from' => $fromState, 'to' => $aadeState, 'local_status' => $fromLocal];
        }

        DB::transaction(function () use ($invoice, $aadeState, $toLocal, $fromState, $fromLocal, $cancelledByMark): void {
            $invoice->forceFill([
                'mydata_state' => $aadeState,
                'local_status' => $toLocal,
            ])->save();

            MyDataMark::create([
                'company_id' => $invoice->company_id,
                'invoice_id' => $invoice->id,
                'mark' => $invoice->mydata_mark,
                'cancellation_mark' => $cancelledByMark,
                'mydata_action' => 'STATE_SYNC',
                'request' => "Συγχρονισμός κατάστασης από ΑΑΔΕ: {$fromState} → {$aadeState}"
                    ." (local_status: {$fromLocal} → {$toLocal})"
                    .($cancelledByMark !== null ? " (ακύρωση με ΜΑΡΚ {$cancelledByMark})" : ''),
                'response' => null,
                'mark_date' => now()->toDateString(),
                'mark_time' => now()->toTimeString(),
            ]);
        });

        Log::info('myDATA state synced from AADE', [
            'company_id' => $invoice->company_id,
            'invoice_id' => $invoice->id,
            'mark' => $invoice->mydata_mark,
            'from' => $fromState,
            'to' => $aadeState,
            'local_status' => $toLocal,
            'cancelled_by_mark' => $cancelledByMark,
        ]);

        // Reflect a cancellation on the WHMCS side (best-effort, never throws;
        // no-op for non-WHMCS invoices). There's no "un-cancel" write-back, so
        // the VALID direction touches local state only. Pass the in-memory model
        // (already carries the just-saved state) — NOT fresh(), whose nullable
        // return would TypeError at this non-nullable boundary, outside the
        // method's own try/catch, after the state is already committed.
        if ($aadeState === 'CANCELLED') {
            app(WhmcsWritebackService::class)->syncCancelledFromLifecycle($invoice);
        }

        return ['changed' => true, 'from' => $fromState, 'to' => $aadeState, 'local_status' => $toLocal];
    }

    /**
     * A MARK we are willing to record as evidence, or null.
     *
     * AADE MARKs are numeric strings (15 digits today); the column holds 40. Both
     * bounds are checked so neither a crafted value nor a future-longer MARK can
     * turn into a truncation or a write error at the audit boundary.
     */
    private static function cleanMark(?string $mark): ?string
    {
        $mark = trim((string) $mark);

        return preg_match('/^\d{1,40}$/', $mark) === 1 ? $mark : null;
    }
}
