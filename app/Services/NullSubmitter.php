<?php

namespace App\Services;

use App\Contracts\EInvoiceSubmitter;
use App\Models\Invoice;
use App\Models\MyDataMark;
use Illuminate\Support\Facades\DB;

/**
 * The deliberate no-op submitter. Used when:
 *
 *   - Company->einvoice_provider = 'none'             (no platform)
 *   - Company->einvoice_provider = 'gr-mydata' AND
 *     Company->mydata_mode       = MyDataMode::Off    (Greek tenant
 *                                                      explicitly not
 *                                                      submitting)
 *   - Future ee-peppol tenants before PeppolSubmitter exists
 *
 * Records the decision NOT to submit in mydata_marks (action='SKIPPED')
 * so the audit trail tells the full story — operators reviewing an
 * invoice later see "we deliberately chose not to file this one" rather
 * than the absence telling them nothing. The invoice's mydata_state
 * stays NULL ("pending" in the UI badge), distinguishing it from
 * 'VALID' (filed) and 'CANCELLED' (filed then cancelled).
 *
 * testConnection() returns true trivially — there's nothing to test;
 * the no-op submitter always "works".
 */
class NullSubmitter implements EInvoiceSubmitter
{
    public function submit(Invoice $invoice): ?MyDataMark
    {
        // Gapless-at-send: for a non-transmitting tenant ('off'/'none'/pre-PEPPOL)
        // this call IS the issuance — there is no AADE round-trip to hang the number
        // on, so allocate the real ΑΑ/invcode/series HERE (idempotent). Without it an
        // 'off' tenant's document would stay provisional forever. The SKIPPED mark
        // below still records the deliberate decision not to file.
        app(InvoiceNumberer::class)->assign($invoice);

        return DB::transaction(function () use ($invoice) {
            return MyDataMark::create([
                'company_id' => $invoice->company_id,
                'invoice_id' => $invoice->id,
                'mark' => null,
                'mydata_action' => 'SKIPPED',
                'mark_date' => now()->toDateString(),
                'mark_time' => now()->toTimeString(),
            ]);
        });
    }

    public function cancel(Invoice $invoice, string $reason = ''): ?MyDataMark
    {
        return DB::transaction(function () use ($invoice, $reason) {
            return MyDataMark::create([
                'company_id' => $invoice->company_id,
                'invoice_id' => $invoice->id,
                'mark' => null,
                'mydata_action' => 'SKIPPED_CANCEL',
                'mark_date' => now()->toDateString(),
                'mark_time' => now()->toTimeString(),
                'request' => $reason !== '' ? "Cancel reason: {$reason}" : null,
            ]);
        });
    }

    public function testConnection(): bool
    {
        return true;
    }
}
