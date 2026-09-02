<?php

namespace App\Support\EInvoice;

use App\Models\Invoice;
use Illuminate\Support\Facades\Log;

/**
 * One structured INFO line per SUCCESSFUL e-invoice filing (OBS-001 tail).
 *
 * The submitters logged only FAILURES. So a filing that reached AADE/the provider
 * and got a MARK left no application-log trace — and the one place an operator
 * looks first from outside the panel, `log_tail --contains=<invcode>`, came back
 * empty for the documents that worked. This closes that: the invcode AND the MARK
 * are in the message text (so a grep on either hits), with channel / type /
 * duration in the context for triage.
 *
 * Emitted right after the MARK is persisted, from the ONE success choke-point in
 * each submitter's performSubmit(). Deliberately never throws — a logging hiccup
 * must not turn a completed filing into a failure.
 */
final class FilingLog
{
    public static function filed(Invoice $invoice, string $mark, string $channel, float $startedAt): void
    {
        $ms = (int) round((microtime(true) - $startedAt) * 1000);
        $invcode = (string) ($invoice->invcode ?? '');

        try {
            Log::info(sprintf(
                'e-invoice filed: %s → MARK %s via %s (%dms)',
                $invcode !== '' ? $invcode : '(no code)',
                $mark !== '' ? $mark : '(no mark)',
                $channel,
                $ms,
            ), [
                'invoice_id' => $invoice->getKey(),
                'company_id' => $invoice->company_id,
                'invcode' => $invcode,
                // The mirror column just persisted by the submitter — the type
                // ACTUALLY filed — not the invoice_type relation (the config value,
                // which can differ on a VAT override and would lazy-load here).
                'mydata_type' => $invoice->mydata_type,
                'mark' => $mark,
                'channel' => $channel,
                'duration_ms' => $ms,
            ]);
        } catch (\Throwable) {
            // A breadcrumb is a convenience, never a reason to fail a filing that
            // already succeeded at AADE/the provider.
        }
    }
}
