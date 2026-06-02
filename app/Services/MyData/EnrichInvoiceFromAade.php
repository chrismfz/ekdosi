<?php

namespace App\Services\MyData;

use App\Models\Invoice;
use App\Models\MyDataMark;
use Illuminate\Support\Facades\DB;

/**
 * Backfills a local invoice from its OFFICIAL AADE record, fetched live by MARK
 * (the `App\Support\MyData\MarkDetail::fromAadeDoc()` array shape). The reason
 * this exists: invoices imported from Epsilon (or older legacy rows) carry a
 * MARK but no AADE **QR URL** — so our reprinted PDF showed the MARK but no QR.
 * The same AADE response also lets us cross-check what differs locally.
 *
 * Policy (locked): **QR is always (re)written**; everything else is
 * **fill-blanks only** — a populated local value is NEVER overwritten
 * (filed/legally-frozen safe). Divergences against AADE are reported, never
 * auto-applied.
 */
class EnrichInvoiceFromAade
{
    /**
     * @param  array<string,mixed>  $aade  MarkDetail::fromAadeDoc() shape
     * @return array{stamped_qr: bool, filled: list<string>, comparison: list<array{label:string, local:?string, aade:?string, match:bool}>}
     */
    public function enrich(Invoice $invoice, array $aade): array
    {
        $filled = [];
        $comparison = [];
        $stampedQr = false;

        DB::transaction(function () use ($invoice, $aade, &$filled, &$comparison, &$stampedQr) {
            $set = [];

            // QR — always (re)write when AADE has one. The whole point.
            $qr = $aade['qrCodeUrl'] ?? null;
            if (is_string($qr) && $qr !== '' && $qr !== $invoice->mydata_url) {
                $set['mydata_url'] = $qr;
                $stampedQr = true;
            }

            // Fill-blanks: never clobber a populated local value.
            if (blank($invoice->company_name) && filled($aade['counterpartName'] ?? null)) {
                $set['company_name'] = $aade['counterpartName'];
                $filled[] = 'επωνυμία αντισυμβαλλόμενου';
            }
            if (blank($invoice->vat_no) && filled($aade['counterpartVat'] ?? null)) {
                $set['vat_no'] = $aade['counterpartVat'];
                $filled[] = 'ΑΦΜ αντισυμβαλλόμενου';
            }
            if (blank($invoice->mydata_type) && filled($aade['invoiceType'] ?? null)) {
                $set['mydata_type'] = $aade['invoiceType'];
                $filled[] = 'τύπος myDATA';
            }
            if (blank($invoice->mydata_state) && filled($aade['state'] ?? null)) {
                $set['mydata_state'] = $aade['state'];
                $filled[] = 'κατάσταση myDATA';
            }

            if ($set !== []) {
                // mydata_* + snapshot columns aren't all fillable → forceFill.
                $invoice->forceFill($set)->save();
            }

            // Keep the audit mark row's QR in sync (fill if empty).
            if ($stampedQr) {
                MyDataMark::query()
                    ->where('company_id', $invoice->company_id)
                    ->where('invoice_id', $invoice->id)
                    ->whereNull('invoice_url')
                    ->update(['invoice_url' => $set['mydata_url']]);
            }

            $comparison = $this->compare($invoice, $aade);
        });

        return ['stamped_qr' => $stampedQr, 'filled' => $filled, 'comparison' => $comparison];
    }

    /**
     * Field-by-field comparison local vs AADE — shows what AGREES (✓) and what
     * DIFFERS (⚠ + both values) for the popup. Read-only: we never auto-change a
     * populated value on a filed document. A field absent on the AADE side
     * (e.g. retail counterpart) is skipped, not flagged.
     *
     * @param  array<string,mixed>  $aade
     * @return list<array{label:string, local:?string, aade:?string, match:bool}>
     */
    private function compare(Invoice $invoice, array $aade): array
    {
        $rows = [];

        $money = [
            ['Καθαρή αξία', (float) $invoice->net_total, $aade['netTotal'] ?? null],
            ['Σύνολο', (float) $invoice->gross_total, $aade['grossTotal'] ?? null],
        ];
        foreach ($money as [$label, $local, $remote]) {
            if ($remote === null) {
                continue;
            }
            $rows[] = [
                'label' => $label,
                'local' => number_format($local, 2, ',', '.').' €',
                'aade' => number_format((float) $remote, 2, ',', '.').' €',
                'match' => abs($local - (float) $remote) <= 0.01,
            ];
        }

        $fields = [
            ['ΑΦΜ αντισυμβαλλόμενου', (string) $invoice->vat_no, $aade['counterpartVat'] ?? null],
            ['Τύπος myDATA', (string) $invoice->mydata_type, isset($aade['invoiceType']) ? (string) $aade['invoiceType'] : null],
            ['Κατάσταση', (string) ($invoice->mydata_state ?? ''), $aade['state'] ?? null],
            ['Σειρά/ΑΑ', (string) $invoice->invcode, $aade['invcode'] ?? null],
        ];
        foreach ($fields as [$label, $local, $remote]) {
            if (! filled($remote)) {
                continue;
            }
            $rows[] = [
                'label' => $label,
                'local' => $local !== '' ? $local : null,
                'aade' => (string) $remote,
                'match' => filled($local) && (string) $local === (string) $remote,
            ];
        }

        $localLines = $invoice->lines()->count();
        $remoteLines = is_array($aade['lines'] ?? null) ? count($aade['lines']) : null;
        if ($remoteLines !== null) {
            $rows[] = [
                'label' => 'Πλήθος γραμμών',
                'local' => (string) $localLines,
                'aade' => (string) $remoteLines,
                'match' => $localLines === $remoteLines,
            ];
        }

        return $rows;
    }
}
