<?php

namespace App\Services\Assistant\Tools;

use App\Models\Company;
use App\Models\Invoice;
use App\Models\PendingWhmcsInvoice;
use App\Support\InvoiceScope;
use Illuminate\Support\Facades\DB;

/**
 * The «Εισερχόμενα» in DETAIL (not just the counter): each staged WHMCS row with
 * its customer, amount, payment date, status and WHMCS line items — PLUS a
 * duplicate-audit that flags rows already invoiced elsewhere, so the operator can
 * clean up a cut-over backlog without double-issuing (or mis-archiving).
 *
 * Duplicate signals, strongest first:
 *   - existing_ekdosi_invoices : a LIVE ekdosi invoice already carries this
 *     whmcs_invoice_id (deterministic — new-app link or backfill).
 *   - legacy_log              : the imported legacy AUTO_INVOICE_LOG has a row
 *     for this WHMCS id → the OLD app processed it.
 *   - same_amount_invoices    : a LIVE invoice of the SAME customer with the SAME
 *     gross (soft signal — verify in the Καρτέλα).
 *
 * `suggestion` combines those with the tenant's cut-over date
 * (whmcs_invoice_min_date, matched against the PAYMENT date):
 *   archive       → a duplicate signal fired (already invoiced somewhere).
 *   file          → paid on/after cut-over AND no duplicate signal (genuinely new).
 *   check_legacy  → paid before cut-over, no signal (probably legacy — verify).
 *   review        → no cut-over set / no payment date to judge by.
 *
 * `status='archived'` + suggestion `file` sets `mis_archived` — «αρχειοθέτησα κάτι
 * που μάλλον έπρεπε να κοπεί». Read-only, tenant-scoped, no WHMCS call.
 */
class WhmcsInboxListTool implements AssistantTool
{
    /** status keyword → the pending statuses it selects (null = no filter). */
    private const STATUS_MAP = [
        'open' => [PendingWhmcsInvoice::STATUS_PENDING_REVIEW, PendingWhmcsInvoice::STATUS_HELD],
        'pending_review' => [PendingWhmcsInvoice::STATUS_PENDING_REVIEW],
        'held' => [PendingWhmcsInvoice::STATUS_HELD],
        'archived' => [PendingWhmcsInvoice::STATUS_REJECTED],
        'filed' => [PendingWhmcsInvoice::STATUS_FILED],
        'drafted' => [PendingWhmcsInvoice::STATUS_DRAFTED],
        'resolved' => [PendingWhmcsInvoice::STATUS_RESOLVED],
        'split' => [PendingWhmcsInvoice::STATUS_SPLIT],
        'all' => null,
    ];

    public function name(): string
    {
        return 'whmcs_inbox_list';
    }

    public function description(): string
    {
        return 'Τα «Εισερχόμενα» WHMCS ΑΝΑΛΥΤΙΚΑ (όχι σκέτος μετρητής): ανά προτιμολόγιο — πελάτης/ΑΦΜ, ποσό, '
            .'ημ/νία πληρωμής, κατάσταση, γραμμές WHMCS — μαζί με ΕΛΕΓΧΟ ΔΙΠΛΟΤΥΠΟΥ (υπάρχον ekdosi παραστατικό '
            .'με ίδιο whmcs id / εγγραφή στο legacy log / παραστατικό ίδιου πελάτη+ποσού) και πρόταση file/archive/'
            .'check. `status`: open (προεπιλογή), pending_review, held, archived (αρχειοθετημένα), filed, drafted, '
            .'resolved, split, all. `status="archived"` δείχνει και «mis_archived» = αρχειοθετημένα που μάλλον '
            .'έπρεπε να κοπούν. Για cut-over cleanup χωρίς διπλοτιμολόγηση. Read-only, δεν καλεί το WHMCS.';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'status' => ['type' => 'string', 'description' => 'open (προεπιλογή) / pending_review / held / archived / filed / drafted / resolved / split / all.'],
                'whmcs_invoice_id' => ['type' => 'integer', 'description' => 'Εστίαση σε ένα συγκεκριμένο WHMCS invoice id.'],
                'duplicates_only' => ['type' => 'boolean', 'description' => 'Μόνο γραμμές με σήμα διπλότυπου (υπάρχον παραστατικό / legacy log / ίδιο ποσό).'],
                'limit' => ['type' => 'integer', 'description' => 'Πόσες γραμμές (1–100, προεπιλογή 25).'],
            ],
        ];
    }

    public function permission(): ?string
    {
        return 'View:PendingWhmcsInvoice';
    }

    public function run(Company $tenant, array $input): array
    {
        $statusKey = is_string($input['status'] ?? null) && array_key_exists($input['status'], self::STATUS_MAP)
            ? $input['status']
            : 'open';
        $statuses = self::STATUS_MAP[$statusKey];
        $whmcsId = (int) ($input['whmcs_invoice_id'] ?? 0);
        $duplicatesOnly = (bool) ($input['duplicates_only'] ?? false);
        $limit = max(1, min(100, (int) ($input['limit'] ?? 25)));

        $cutover = $tenant->whmcs_invoice_min_date?->format('Y-m-d');

        // duplicates_only scans a wider window (the cap) to still surface up to
        // $limit flagged rows; `truncated` below flags when that window filled,
        // so a huge backlog isn't silently under-reported.
        $scanCap = $duplicatesOnly ? min(500, max($limit * 8, $limit)) : $limit;

        // One base query for both the scan and the total count — no duplicated
        // filter chain to drift out of sync.
        $matching = PendingWhmcsInvoice::query()
            ->where('company_id', $tenant->getKey())
            ->when($statuses !== null, fn ($q) => $q->whereIn('status', $statuses))
            ->when($whmcsId > 0, fn ($q) => $q->where('whmcs_invoice_id', $whmcsId));

        // Full count of the matching set (independent of the scan window) so the
        // caller always sees how many rows exist in this status, not just returned.
        $total = (clone $matching)->count();

        $rows = $matching
            ->with('customer:id,name,afm')
            ->orderByDesc('id')
            ->limit($scanCap)
            ->get();

        if ($rows->isEmpty()) {
            return ['status' => $statusKey, 'cutover' => $cutover, 'cutover_matches' => 'datepaid', 'total' => $total, 'count' => 0, 'scanned' => 0, 'truncated' => false, 'rows' => []];
        }

        $whmcsIds = $rows->pluck('whmcs_invoice_id')->filter()->map(fn ($v) => (int) $v)->unique()->values()->all();

        // Batched strong signals (one query each).
        $legacyLog = $whmcsIds === [] ? collect() : DB::table('whmcs_invoice_log')
            ->where('company_id', $tenant->getKey())
            ->whereIn('whmcs_invoice_id', $whmcsIds)
            ->select('whmcs_invoice_id', DB::raw('COUNT(*) as hits'), DB::raw('MAX(message) as sample'))
            ->groupBy('whmcs_invoice_id')
            ->get()
            ->keyBy('whmcs_invoice_id');

        // Existing ekdosi invoice(s) already carrying this whmcs id — the strong
        // duplicate signal. LIVE only: a CANCELLED ekdosi invoice is void and must
        // NOT read as «already invoiced» (else the operator archives a row that
        // needs re-filing) — consistent with the same-amount signal below. Grouped
        // (not pluck) so multiple docs sharing one whmcs id all surface, not one
        // arbitrary invcode.
        $existingLinks = collect();
        if ($whmcsIds !== []) {
            $eq = Invoice::query()
                ->where('invoices.company_id', $tenant->getKey())
                ->whereIn('invoices.whmcs_invoice_id', $whmcsIds);
            InvoiceScope::live($eq, 'invoices.');
            $existingLinks = $eq->get(['invoices.invcode', 'invoices.whmcs_invoice_id'])
                ->groupBy('whmcs_invoice_id')
                ->map(fn ($g) => $g->pluck('invcode')->values()->all());
        }

        // Batch the soft same-amount signal: all LIVE invoices of the page's
        // customers, matched in PHP by gross — one query instead of one per row.
        $custIds = $rows->pluck('customer_id')->filter()->unique()->values()->all();
        $custInvoices = collect();
        if ($custIds !== []) {
            $q = Invoice::query()
                ->where('invoices.company_id', $tenant->getKey())
                ->whereIn('invoices.customer_id', $custIds);
            InvoiceScope::live($q, 'invoices.');
            $custInvoices = $q->get(['invoices.id', 'invoices.invcode', 'invoices.customer_id', 'invoices.gross_total'])
                ->groupBy('customer_id');
        }

        $out = [];
        $cappedAtLimit = false;
        foreach ($rows as $i => $row) {
            $payload = is_array($row->payload) ? $row->payload : [];
            $amount = round((float) ($payload['total'] ?? 0), 2);
            $datepaid = (string) ($payload['datepaid'] ?? '');
            // WHMCS writes '0000-00-00 00:00:00' for a not-yet-paid invoice
            // (whmcs:fetch-unpaid rows) — treat that as «no payment date», not a
            // real pre-cut-over date, or every unpaid row reads as probable-legacy.
            $paidDate = ($datepaid !== '' && ! str_starts_with($datepaid, '0000')) ? substr($datepaid, 0, 10) : '';

            $existing = $existingLinks[$row->whmcs_invoice_id] ?? [];
            $log = $legacyLog[$row->whmcs_invoice_id] ?? null;
            $sameAmount = ($amount > 0.005 && $row->customer_id !== null)
                ? ($custInvoices[$row->customer_id] ?? collect())
                    ->filter(fn ($i): bool => abs((float) $i->gross_total - $amount) < 0.01)
                    ->pluck('invcode')->take(5)->values()->all()
                : [];
            // Don't double-report the invoice already named as the existing link
            // under both signals — same_amount is the SOFT (other-doc) signal.
            $sameAmount = array_values(array_diff($sameAmount, $existing));

            $hasDup = $existing !== [] || ($log?->hits ?? 0) > 0 || $sameAmount !== [];
            if ($duplicatesOnly && ! $hasDup) {
                continue;
            }

            $suggestion = $this->suggest($hasDup, $cutover, $paidDate);

            $out[] = [
                'whmcs_invoice_id' => (int) $row->whmcs_invoice_id,
                'status' => $row->status,
                'customer' => $row->customer?->name ?? $row->whmcsClientName(),
                'afm' => $row->customer?->afm,
                'amount' => $amount,
                'currency' => 'EUR',
                'datepaid' => $datepaid ?: null,
                'lines' => $this->lines($payload),
                'duplicate' => [
                    'existing_ekdosi_invoices' => $existing ?: null,
                    'legacy_log_hits' => (int) ($log?->hits ?? 0),
                    'legacy_log_sample' => $log?->sample,
                    'same_amount_invoices' => $sameAmount,
                ],
                'suggestion' => $suggestion,
                'mis_archived' => $row->status === PendingWhmcsInvoice::STATUS_REJECTED && $suggestion === 'file',
            ];

            if (count($out) >= $limit) {
                // Stopped early if scanned rows remain after this one — in
                // duplicates_only that tail may hold more flagged rows.
                $cappedAtLimit = ($i + 1) < $rows->count();
                break;
            }
        }

        return [
            'status' => $statusKey,
            'cutover' => $cutover,
            'cutover_matches' => 'datepaid',
            'total' => $total,
            'count' => count($out),
            'scanned' => $rows->count(),
            // More rows may exist beyond what's returned. In duplicates_only that
            // means the scan window filled (more unscanned rows could hold dupes) OR
            // the output cap hit mid-window — NOT merely that the status has more
            // non-duplicate rows. In normal mode it means the status has more than
            // the page. Narrow by status/whmcs_invoice_id or raise limit.
            'truncated' => $duplicatesOnly
                ? ($rows->count() >= $scanCap || $cappedAtLimit)
                : ($total > $rows->count()),
            'rows' => $out,
        ];
    }

    private function suggest(bool $hasDup, ?string $cutover, string $paidDate): string
    {
        if ($hasDup) {
            return 'archive';   // already invoiced somewhere → don't re-issue
        }
        if ($cutover === null || $paidDate === '') {
            return 'review';    // no basis to auto-judge
        }

        return $paidDate >= $cutover ? 'file' : 'check_legacy';
    }

    /** Normalise the WHMCS payload line items to [{description, amount}]. */
    private function lines(array $payload): array
    {
        $items = $payload['items']['item'] ?? null;
        if (! is_array($items) || $items === []) {
            return [];
        }
        if (! array_is_list($items)) {
            $items = [$items];   // WHMCS returns a bare object for a single line
        }

        $out = [];
        foreach ($items as $item) {
            if (! is_array($item)) {
                continue;
            }
            $descr = trim((string) ($item['description'] ?? ''));
            if ($descr === '') {
                continue;   // WHMCS pads with empty rows
            }
            $out[] = [
                'description' => $descr,
                'amount' => round((float) ($item['amount'] ?? 0), 2),
            ];
        }

        return $out;
    }
}
