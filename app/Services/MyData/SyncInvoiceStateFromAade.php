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
     * @return array{changed: bool, from: ?string, to: string, local_status: ?string}
     */
    public function sync(Invoice $invoice, string $aadeState): array
    {
        $aadeState = strtoupper(trim($aadeState));

        // Only the two terminal myDATA states are syncable. A blank/unknown
        // value (e.g. AADE didn't return a state) must never silently wipe ours.
        if (! in_array($aadeState, ['VALID', 'CANCELLED'], true)) {
            throw new RuntimeException("Μη αναμενόμενη κατάσταση ΑΑΔΕ: «{$aadeState}».");
        }

        $fromState = $invoice->mydata_state;
        $fromLocal = $invoice->local_status;

        if ($fromState === $aadeState) {
            return ['changed' => false, 'from' => $fromState, 'to' => $aadeState, 'local_status' => $fromLocal];
        }

        $toLocal = match ($aadeState) {
            'CANCELLED' => 'cancelled',
            // Un-cancel a wrongly-cancelled doc; otherwise leave business intent.
            'VALID' => $fromLocal === 'cancelled' ? 'active' : $fromLocal,
        };

        DB::transaction(function () use ($invoice, $aadeState, $toLocal, $fromState, $fromLocal): void {
            $invoice->forceFill([
                'mydata_state' => $aadeState,
                'local_status' => $toLocal,
            ])->save();

            MyDataMark::create([
                'company_id' => $invoice->company_id,
                'invoice_id' => $invoice->id,
                'mark' => $invoice->mydata_mark,
                'mydata_action' => 'STATE_SYNC',
                'request' => "Συγχρονισμός κατάστασης από ΑΑΔΕ: {$fromState} → {$aadeState}"
                    ." (local_status: {$fromLocal} → {$toLocal})",
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
        ]);

        // Reflect a cancellation on the WHMCS side (best-effort, never throws;
        // no-op for non-WHMCS invoices). There's no "un-cancel" write-back, so
        // the VALID direction touches local state only.
        if ($aadeState === 'CANCELLED') {
            app(WhmcsWritebackService::class)->syncCancelledFromLifecycle($invoice->fresh());
        }

        return ['changed' => true, 'from' => $fromState, 'to' => $aadeState, 'local_status' => $toLocal];
    }
}
