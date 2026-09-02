<?php

namespace App\Services\MyData;

use App\Models\Invoice;
use App\Models\MyDataMark;
use App\Support\Afm;
use App\Support\Money;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Backfills a local invoice from its OFFICIAL AADE record, fetched live by MARK
 * (the `App\Support\MyData\MarkDetail::fromAadeDoc()` array shape). The reason
 * this exists: invoices imported from Epsilon (or older legacy rows) carry a
 * MARK but no AADE **QR URL** — so our reprinted PDF showed the MARK but no QR.
 * The same AADE response also lets us cross-check what differs locally.
 *
 * Policy (locked): **QR is (re)written** (except for a CANCELLED AADE doc — we
 * won't put a QR resolving to a cancelled record onto a locally-live invoice);
 * everything else is **fill-blanks only** — a populated local value is NEVER
 * overwritten (filed/legally-frozen safe). `mydata_state` is deliberately NOT
 * auto-filled: it's a critical orthogonal status (vs local_status) that drives
 * the submitter guard + lifecycle actions. Divergences are reported, never
 * auto-applied.
 */
class EnrichInvoiceFromAade
{
    /**
     * @param  array<string,mixed>  $aade  MarkDetail::fromAadeDoc() shape
     * @return array{stamped_qr: bool, qr_skipped_cancelled: bool, filled: list<string>, comparison: list<array{label:string, local:?string, aade:?string, match:bool}>}
     */
    public function enrich(Invoice $invoice, array $aade): array
    {
        // Snapshot the local values BEFORE filling, so the comparison reflects
        // the true local-vs-AADE picture (a field we fill-blank in this same
        // call must not then read back as a trivial "match").
        $before = [
            'vat_no' => $invoice->vat_no,
            'company_name' => $invoice->company_name,
            'mydata_type' => $invoice->mydata_type,
            'mydata_state' => $invoice->mydata_state,
            'invcode' => $invoice->invcode,
            // Compare the FILED roll-up, the same basis the reconciler uses:
            // AADE's totals round per VAT rate and its gross carries the [208]
            // adjustment, neither of which net_total/gross_total do. Reading the
            // columns here made this per-invoice cross-check contradict the
            // console for every withholding / multi-rate-discounted invoice.
            'net_total' => FiledInvoiceTotals::for($invoice)->net,
            'gross_total' => FiledInvoiceTotals::for($invoice)->gross,
            'lines' => $invoice->lines()->count(),
        ];

        $qr = $aade['qrCodeUrl'] ?? null;
        $qrValid = is_string($qr) && Str::startsWith($qr, ['http://', 'https://']);
        $aadeCancelled = ($aade['state'] ?? null) === 'CANCELLED';
        // Only treat the AADE doc as the counterpart source for OUR outbound
        // doc (for an inbound doc the "counterpart" would be us).
        $outbound = ($aade['direction'] ?? 'outbound') !== 'inbound';

        $filled = [];
        $stampedQr = false;

        DB::transaction(function () use ($invoice, $aade, $qr, $qrValid, $aadeCancelled, $outbound, &$filled, &$stampedQr) {
            $set = [];

            // QR — (re)write, unless AADE has cancelled the doc.
            if ($qrValid && ! $aadeCancelled) {
                if ($qr !== $invoice->mydata_url) {
                    $set['mydata_url'] = $qr;
                }
                $stampedQr = true;

                // Sync the INSERT audit row's QR when empty — driven by the
                // resolved URL (not by whether the invoice column changed) and
                // scoped to the INSERT row (invoice_url is meaningless on a
                // DRY_RUN / CANCEL / SKIPPED row).
                MyDataMark::query()
                    ->where('company_id', $invoice->company_id)
                    ->where('invoice_id', $invoice->id)
                    ->where('mydata_action', 'INSERT')
                    ->whereNull('invoice_url')
                    ->update(['invoice_url' => $qr]);
            }

            // Fill-blanks (display/snapshot columns only). Counterpart fields
            // only when the AADE doc is ours-outbound.
            if ($outbound && blank($invoice->company_name) && filled($aade['counterpartName'] ?? null)) {
                $set['company_name'] = $aade['counterpartName'];
                $filled[] = 'επωνυμία αντισυμβαλλόμενου';
            }
            // MYD-009: an all-zeros «000000000» is a PLACEHOLDER, not an identity, so
            // it must be repairable from what AADE reports. Gating on blank() alone
            // made this self-heal — the one tool that can fix a FILED legacy row —
            // skip exactly the rows that need it.
            if ($outbound && Afm::canonicalVat($invoice->vat_no) === null && filled($aade['counterpartVat'] ?? null)) {
                $set['vat_no'] = $aade['counterpartVat'];
                $filled[] = 'ΑΦΜ αντισυμβαλλόμενου';
            }
            if (blank($invoice->mydata_type) && filled($aade['invoiceType'] ?? null)) {
                $set['mydata_type'] = $aade['invoiceType'];
                $filled[] = 'τύπος myDATA';
            }

            if ($set !== []) {
                // mydata_url + some snapshot columns aren't fillable → forceFill.
                $invoice->forceFill($set)->save();
            }
        });

        return [
            'stamped_qr' => $stampedQr,
            'qr_skipped_cancelled' => $qrValid && $aadeCancelled,
            'filled' => $filled,
            'comparison' => $this->compare($before, $aade),
        ];
    }

    /**
     * Field-by-field comparison local (pre-fill snapshot) vs AADE — what AGREES
     * (✓) and what DIFFERS (⚠, with both values). Read-only. Skips a field
     * absent on either side: a blank LOCAL field isn't a "conflict" (it's a
     * fill-blank, reported separately), and a field AADE doesn't return (e.g.
     * retail counterpart) isn't flagged.
     *
     * @param  array<string,mixed>  $before  pre-fill local snapshot
     * @param  array<string,mixed>  $aade
     * @return list<array{label:string, local:?string, aade:?string, match:bool}>
     */
    private function compare(array $before, array $aade): array
    {
        $rows = [];

        foreach ([
            ['Καθαρή αξία', $before['net_total'], $aade['netTotal'] ?? null],
            ['Σύνολο', $before['gross_total'], $aade['grossTotal'] ?? null],
        ] as [$label, $local, $remote]) {
            if ($remote === null) {
                continue;
            }
            // Local figure not reconstructable (no lines, or withholding with no
            // §8.4 category): say so rather than rendering a misleading 0,00.
            if ($local === null) {
                $rows[] = [
                    'label' => $label,
                    'local' => '—',
                    'aade' => number_format((float) $remote, 2, ',', '.').' €',
                    'match' => false,
                ];

                continue;
            }
            $rows[] = [
                'label' => $label,
                'local' => number_format((float) $local, 2, ',', '.').' €',
                'aade' => number_format((float) $remote, 2, ',', '.').' €',
                // Same integer-cent rule as the reconciler, so the two surfaces
                // never disagree about the same pair of amounts (a float 0.01
                // tolerance is magnitude-dependent).
                'match' => ! Money::differsByCent((float) $local, (float) $remote),
            ];
        }

        // String fields — populated-vs-populated only. `$ws` strips whitespace
        // before matching: local invcode is `code.aa` (e.g. «ΤΙΜ385») while AADE
        // joins series+aa with a space («ΤΙΜ 385») — without this every row
        // would falsely flag a Σειρά/ΑΑ difference.
        foreach ([
            ['ΑΦΜ αντισυμβαλλόμενου', $before['vat_no'], $aade['counterpartVat'] ?? null, false],
            ['Τύπος myDATA', $before['mydata_type'], $aade['invoiceType'] ?? null, false],
            ['Κατάσταση', $before['mydata_state'], $aade['state'] ?? null, false],
            ['Σειρά/ΑΑ', $before['invcode'], $aade['invcode'] ?? null, true],
        ] as [$label, $local, $remote, $ws]) {
            if (! filled($local) || ! filled($remote)) {
                continue;
            }
            $l = $ws ? preg_replace('/\s+/u', '', (string) $local) : (string) $local;
            $r = $ws ? preg_replace('/\s+/u', '', (string) $remote) : (string) $remote;
            $rows[] = [
                'label' => $label,
                'local' => (string) $local,
                'aade' => (string) $remote,
                'match' => $l === $r,
            ];
        }

        $remoteLines = is_array($aade['lines'] ?? null) ? count($aade['lines']) : null;
        if ($remoteLines !== null) {
            $rows[] = [
                'label' => 'Πλήθος γραμμών',
                'local' => (string) $before['lines'],
                'aade' => (string) $remoteLines,
                'match' => $before['lines'] === $remoteLines,
            ];
        }

        return $rows;
    }
}
