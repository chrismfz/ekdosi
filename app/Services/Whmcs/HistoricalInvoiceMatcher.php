<?php

namespace App\Services\Whmcs;

use App\Models\Company;
use App\Models\Invoice;
use App\Support\InvoiceScope;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Recovers the historical WHMCS#→ekdosi-ΤΠΥ link that the legacy import could
 * not carry (no stored mapping survived — verified NULL/empty in prod). It
 * INFERS the link from invoice content and records how confidently.
 *
 * Why content-matching, tenant-wide (not via the customer):
 * the WHMCS install never enabled native VAT, so tblclients.tax_id is empty
 * and customers.whmcs_client_id is NULL — there is NO reliable client link to
 * narrow by. But the probe (whmcs-matcher-determinism-myip.txt) showed the
 * content is highly distinctive:
 *   - invoice_lines.product_descr: 9.784 distinct / 9.903 — almost a natural
 *     key (it embeds product + domain + billing period, e.g.
 *     "MyMail 100 - ktima-pavlidis.gr (25/05/2026 - 24/06/2026)") and the same
 *     string appears verbatim on the WHMCS line.
 *   - (gross_total, issued_at) is 98% unique tenant-wide as a fallback.
 *
 * Two tiers (a WHMCS invoice that matches neither is left for operator review —
 * never written):
 *   - HIGH  / 'line_text':   exactly one ekdosi invoice carries a line whose
 *                            normalised text equals a WHMCS line's text, AND
 *                            its gross equals the WHMCS total (±€0.02). The
 *                            amount cross-check guards the rare duplicate text.
 *   - MEDIUM/ 'amount_date': exactly one ekdosi invoice has gross == WHMCS
 *                            total AND issued within ±1 day of the WHMCS date.
 *
 * Writes are idempotent (keyed on (company_id, whmcs_invoice_id)) and NEVER
 * overwrite a 'manual' link.
 */
class HistoricalInvoiceMatcher
{
    /** Gross-amount equality tolerance (rounding between the two systems). */
    private const AMOUNT_TOLERANCE = 0.02;

    /**
     * Build the per-tenant lookup index ONCE (live invoices issued on/after
     * $since, with their line descriptions). Returned as plain arrays so
     * matching is pure and cheap — no per-WHMCS-invoice DB query.
     *
     * @return array{
     *   text: array<string, array<int, true>>,
     *   amountDate: array<string, array<int, true>>,
     *   gross: array<int, float>
     * }
     */
    public function buildIndex(Company $tenant, Carbon $since): array
    {
        // Live invoices only — a cancelled/AADE-cancelled ΤΠΥ is not a link
        // target. Issued within the window (a small back-buffer absorbs the
        // ±1-day amount_date lookup at the window edge).
        $invoices = InvoiceScope::live(
            Invoice::query()
                ->where('company_id', $tenant->id)
                ->whereDate('issued_at', '>=', $since->copy()->subDay()->toDateString())
        )->get(['id', 'gross_total', 'issued_at']);

        $gross = [];
        $amountDate = [];
        foreach ($invoices as $inv) {
            $gross[$inv->id] = (float) $inv->gross_total;
            $date = $inv->issued_at?->toDateString();
            if ($date !== null) {
                $key = $this->amountDateKey((float) $inv->gross_total, $date);
                $amountDate[$key][$inv->id] = true;
            }
        }

        // Line text → invoice ids. One query for all lines of the windowed
        // invoices (avoids N+1). product_descr is the matchable text.
        $text = [];
        if ($invoices->isNotEmpty()) {
            DB::table('invoice_lines')
                ->where('company_id', $tenant->id)
                ->whereIn('invoice_id', $invoices->pluck('id')->all())
                ->whereNotNull('product_descr')
                ->select('invoice_id', 'product_descr')
                ->orderBy('id')
                ->each(function ($line) use (&$text): void {
                    $norm = $this->normaliseText((string) $line->product_descr);
                    if ($norm !== '') {
                        $text[$norm][(int) $line->invoice_id] = true;
                    }
                });
        }

        return ['text' => $text, 'amountDate' => $amountDate, 'gross' => $gross];
    }

    /**
     * Match ONE WHMCS invoice against the index. Returns the ekdosi invoice id
     * + method + confidence, or null when no confident single match exists
     * (ambiguous / unmatched → left for operator review, never written).
     *
     * @param  array{total: float|string, date: string, descriptions: list<string>}  $whmcs
     * @param  array{text: array<string, array<int, true>>, amountDate: array<string, array<int, true>>, gross: array<int, float>}  $index
     * @return array{invoice_id: int, method: string, confidence: string}|null
     */
    public function match(array $whmcs, array $index): ?array
    {
        $total = round((float) $whmcs['total'], 2);
        // €0 WHMCS invoices (free renewals, account credit top-ups like
        // "Προσθήκη Χρημάτων") never produced a ΤΠΥ — don't try to link them.
        if ($total <= 0.0) {
            return null;
        }
        $date = (string) ($whmcs['date'] ?? '');

        // Tier 1 — line text (HIGH). Collect every ekdosi invoice whose line
        // text equals one of this WHMCS invoice's line texts.
        $textHits = [];
        foreach ($whmcs['descriptions'] as $descr) {
            $norm = $this->normaliseText((string) $descr);
            if ($norm === '' || ! isset($index['text'][$norm])) {
                continue;
            }
            foreach ($index['text'][$norm] as $invoiceId => $_) {
                $textHits[$invoiceId] = true;
            }
        }
        if (count($textHits) === 1) {
            $invoiceId = (int) array_key_first($textHits);
            // Amount cross-check guards the rare non-unique text. If the gross
            // doesn't line up, the text match is suspect → fall through to the
            // amount_date tier rather than asserting a HIGH link.
            if (abs(($index['gross'][$invoiceId] ?? -1.0) - $total) <= self::AMOUNT_TOLERANCE) {
                return ['invoice_id' => $invoiceId, 'method' => 'line_text', 'confidence' => 'high'];
            }
        }

        // Tier 2 — amount + date ±1 (MEDIUM). Unique single hit across the
        // three-day window only.
        if ($date !== '') {
            $candidates = [];
            foreach ($this->datesAround($date) as $d) {
                foreach ($index['amountDate'][$this->amountDateKey($total, $d)] ?? [] as $invoiceId => $_) {
                    $candidates[$invoiceId] = true;
                }
            }
            if (count($candidates) === 1) {
                return [
                    'invoice_id' => (int) array_key_first($candidates),
                    'method' => 'amount_date',
                    'confidence' => 'medium',
                ];
            }
        }

        return null;
    }

    /**
     * Idempotently persist a recovered link. Keyed on
     * (company_id, whmcs_invoice_id). NEVER overwrites a 'manual' link.
     * Returns true if a row was written/updated, false if skipped (manual).
     */
    public function persist(Company $tenant, int $whmcsInvoiceId, array $match): bool
    {
        $existing = DB::table('whmcs_invoice_log')
            ->where('company_id', $tenant->id)
            ->where('whmcs_invoice_id', $whmcsInvoiceId)
            ->first(['id', 'match_method']);

        if ($existing !== null && $existing->match_method === 'manual') {
            return false;   // operator-set link is authoritative — leave it
        }

        $values = [
            'invoice_id' => $match['invoice_id'],
            'match_method' => $match['method'],
            'match_confidence' => $match['confidence'],
            'matched_at' => now(),
            'message' => 'Auto-matched ('.$match['method'].'/'.$match['confidence'].')',
            'updated_at' => now(),
        ];

        if ($existing !== null) {
            DB::table('whmcs_invoice_log')->where('id', $existing->id)->update($values);
        } else {
            DB::table('whmcs_invoice_log')->insert($values + [
                'company_id' => $tenant->id,
                'whmcs_invoice_id' => $whmcsInvoiceId,
                'created_at' => now(),
            ]);
        }

        return true;
    }

    /**
     * Canonical line text for matching: collapse ALL whitespace (incl. the
     * newlines WHMCS embeds in config-option descriptions) to single spaces,
     * trim, casefold. Robust to cosmetic differences without losing the
     * domain/period tokens that make the text distinctive.
     */
    private function normaliseText(string $value): string
    {
        $collapsed = preg_replace('/\s+/u', ' ', $value) ?? $value;

        return mb_strtolower(trim($collapsed));
    }

    private function amountDateKey(float $amount, string $date): string
    {
        return number_format($amount, 2, '.', '').'|'.$date;
    }

    /** The match date plus ±1 day (auto-issue lag between WHMCS and ekdosi). */
    private function datesAround(string $date): array
    {
        try {
            $d = Carbon::parse($date);
        } catch (\Throwable $e) {
            return [$date];
        }

        return [
            $d->copy()->subDay()->toDateString(),
            $d->toDateString(),
            $d->copy()->addDay()->toDateString(),
        ];
    }
}
