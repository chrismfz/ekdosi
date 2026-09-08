<?php

namespace App\Http\Controllers\Webhooks;

use App\Models\Company;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\PendingWhmcsInvoice;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * Customer-facing (via WHMCS): the "Εκδοθέντα Παραστατικά" list a reseller sees
 * in their WHMCS client area. Returns every ISSUED ekdosi παραστατικό produced
 * from a WHMCS invoice THIS reseller paid — their own documents AND the ones
 * routed to third parties they set up in «Παραστατικά σε τρίτους (v2)».
 *
 *   POST /webhooks/whmcs/{slug}/issued-for-client
 *   X-Webhook-Signature: sha256=<hex hmac of the raw body>
 *   body: { "whmcs_userid": 793, "whmcs_invoice_ids": [31588, 31590, ...] }
 *
 * TWO leak-proof sources, both anchored to invoices the reseller PAID (never to
 * a third party's ΑΦΜ — that party may buy elsewhere too, so an ΑΦΜ match would
 * expose unrelated invoices):
 *   1. BRIDGE-DERIVED (post-cutover): pending_whmcs_invoices where whmcs_userid =
 *      {whmcs_userid} → own (invoice_id) + routed split parts (whmcs_pending_id).
 *      A split maps one WHMCS invoice to N παραστατικά, which the 1:1 WHMCS-side
 *      mark store can't represent — so ekdosi is the authority here.
 *   2. HISTORICAL (pre-bridge, imported): ekdosi invoices whose deterministic
 *      `whmcs_invoice_id` (backfilled from the exact `tblinvoices.invoiced ===
 *      invoices.legacy_id` FK) is one of the reseller's OWN WHMCS invoice ids.
 *      The plugin gathers those ids from `tblinvoices.userid = {whmcs_userid}`
 *      (WHMCS-side authority) and sends them here. This is leak-proof precisely
 *      because the boundary is the reseller's WHMCS invoice id — NOT an ΑΦΜ — so
 *      even a legacy third-party routing (issued to another party ON the
 *      reseller's WHMCS invoice) is legitimately theirs to see, while an
 *      unrelated invoice of the same ΑΦΜ is not reachable.
 *
 * Read-only. Only LIVE documents are returned — drafts (unissued) and cancelled /
 * AADE-cancelled (void) are excluded via isPubliclyViewable(). Each row carries
 * `verify_url` (AADE/πάροχος public verification link, invoices.mydata_url) and
 * `verify_kind` ('provider' for ΥΠΑΕΣ / 'aade' for direct-myDATA) so the plugin
 * can label it correctly, plus `has_pdf`. `has_pdf` is true only for bridge-
 * derived rows: a historical row has no pending row for the PDF proxy to
 * authorize against, so it shows the verify link but no (would-always-404) PDF
 * button. The raw signed PDF URL is deliberately NOT returned — the plugin
 * proxies the bytes so an unguessable bearer link never reaches the browser.
 *
 * Auth: HMAC-SHA256 over the raw body (same scheme as invoices-by-afm); the
 * whmcs_userid + id list are inside the signed body, so nothing is replayable
 * across clients.
 */
class WhmcsClientIssuedInvoicesController
{
    /**
     * Upper bound on WHMCS invoices (pending rows) folded into one list. Feeds a
     * single unpaginated client-area table; a real reseller is far below this,
     * and the cap keeps a pathological history from blowing up memory/latency.
     */
    private const MAX_PENDING_ROWS = 500;

    /** Upper bound on the historical WHMCS invoice ids accepted in one request. */
    private const MAX_HISTORICAL_IDS = 2000;

    public function __invoke(Request $request, string $slug): JsonResponse
    {
        $tenant = Company::query()->where('slug', $slug)->first();
        if ($tenant === null) {
            $this->logRejection($request, $slug, 'tenant_not_found');

            return new JsonResponse(['error' => 'tenant_not_found'], Response::HTTP_NOT_FOUND);
        }

        $secret = (string) ($tenant->whmcs_webhook_secret ?? '');
        if ($secret === '') {
            $this->logRejection($request, $slug, 'webhook_secret_not_configured');

            return new JsonResponse([
                'error' => 'webhook_secret_not_configured',
                'message' => 'Tenant exists but has no whmcs_webhook_secret. Set it in the company config form.',
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        if (! $this->verifySignature($request, $secret)) {
            $this->logRejection($request, $slug, 'invalid_signature');

            return new JsonResponse(['error' => 'invalid_signature'], Response::HTTP_UNAUTHORIZED);
        }

        $whmcsUserid = (int) $request->input('whmcs_userid', 0);
        if ($whmcsUserid <= 0) {
            $this->logRejection($request, $slug, 'invalid_whmcs_userid');

            return new JsonResponse(['error' => 'invalid_whmcs_userid'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        // 'provider' (ΥΠΑΕΣ viewinvoice link) vs 'aade' (AADE QR verify) — a
        // tenant-level label hint the plugin uses on the verification button.
        $verifyKind = $tenant->einvoice_provider === 'gr-provider' ? 'provider' : 'aade';

        // The reseller's OWN ekdosi customer(s), for grouping historical rows into
        // «own» vs «third party». Bridge rows use the precise pending.customer_id
        // instead; here we fall back to «own» when the reseller isn't linked.
        $ownCustomerIds = Customer::query()
            ->where('company_id', $tenant->id)
            ->where('whmcs_client_id', $whmcsUserid)
            ->pluck('id')
            ->all();

        $rows = [];
        $seen = [];

        $this->collectBridgeDerived($rows, $seen, $tenant, $whmcsUserid, $verifyKind);
        $this->collectHistorical($rows, $seen, $tenant, $request, $ownCustomerIds, $verifyKind);

        // Newest first (issued date, then id as a stable tiebreak).
        usort($rows, static fn (array $a, array $b): int => [$b['issued_at'] ?? '', $b['ekdosi_invoice_id']]
            <=> [$a['issued_at'] ?? '', $a['ekdosi_invoice_id']]);

        return new JsonResponse([
            'found' => true,
            'whmcs_userid' => $whmcsUserid,
            'rows' => array_values($rows),
        ], Response::HTTP_OK);
    }

    /**
     * Source 1: documents produced from WHMCS invoices staged in the bridge for
     * this reseller (own via the 1:1 invoice_id, routed via the split parts).
     *
     * @param  array<int, array<string, mixed>>  $rows
     * @param  array<int, true>  $seen
     */
    private function collectBridgeDerived(array &$rows, array &$seen, Company $tenant, int $whmcsUserid, string $verifyKind): void
    {
        $pendings = PendingWhmcsInvoice::query()
            ->where('company_id', $tenant->id)
            ->where('whmcs_userid', $whmcsUserid)
            ->with([
                'invoice.invoiceType:id,name',
                'invoice.customer:id,name,afm',
                'splitInvoices.invoiceType:id,name',
                'splitInvoices.customer:id,name,afm',
            ])
            ->orderByDesc('id')
            ->limit(self::MAX_PENDING_ROWS)
            ->get();

        foreach ($pendings as $pending) {
            if ($pending->invoice !== null) {
                $this->collect($rows, $seen, $pending->whmcs_invoice_id, $pending->invoice, true, true, $verifyKind);
            }
            foreach ($pending->splitInvoices as $split) {
                // "Own" when the split part's counterpart is the same ekdosi
                // customer the WHMCS invoice matched to (the reseller); routed
                // parts carry the third party's customer_id. null === null holds
                // too (an unmatched pending's own retail part stays «own»).
                $isOwn = $split->customer_id === $pending->customer_id;
                $this->collect($rows, $seen, $pending->whmcs_invoice_id, $split, $isOwn, true, $verifyKind);
            }
        }
    }

    /**
     * Source 2: pre-bridge imported documents, matched by the deterministic
     * `invoices.whmcs_invoice_id` FK against the reseller's OWN WHMCS invoice ids
     * (supplied by the plugin from tblinvoices.userid). Rows already collected via
     * the bridge path are skipped (de-dupe by ekdosi invoice id).
     *
     * @param  array<int, array<string, mixed>>  $rows
     * @param  array<int, true>  $seen
     * @param  array<int, int>  $ownCustomerIds
     */
    private function collectHistorical(array &$rows, array &$seen, Company $tenant, Request $request, array $ownCustomerIds, string $verifyKind): void
    {
        $ids = collect((array) $request->input('whmcs_invoice_ids', []))
            ->map(static fn ($v): int => (int) $v)
            ->filter(static fn (int $v): bool => $v > 0)
            ->unique()
            ->take(self::MAX_HISTORICAL_IDS)
            ->values()
            ->all();
        if ($ids === []) {
            return;
        }

        // Bound the historical rows too (newest first), so the single unpaginated
        // client-area table stays sane even for a reseller with thousands of
        // pre-bridge invoices — mirrors MAX_PENDING_ROWS on the bridge source.
        // Full pagination is the real fix (docs/BACKLOG.md).
        $invoices = Invoice::query()
            ->where('company_id', $tenant->id)
            ->whereIn('whmcs_invoice_id', $ids)
            ->with(['invoiceType:id,name', 'customer:id,name,afm'])
            ->orderByDesc('id')
            ->limit(self::MAX_PENDING_ROWS)
            ->get();

        foreach ($invoices as $invoice) {
            // Grouping only (both are the reseller's entitled docs): «own» when the
            // counterpart is the reseller's linked customer; when the reseller has
            // no linked customer we can't tell → default to «own».
            $isOwn = $ownCustomerIds === []
                || ($invoice->customer_id !== null && in_array($invoice->customer_id, $ownCustomerIds, true));
            // allowPdf=false: a pre-bridge invoice has no pending row, so the PDF
            // proxy endpoint (which authorizes via pending rows only) can't serve
            // it — advertising a PDF button that always 404s would be worse than
            // none. Historical rows still carry the AADE/πάροχος verify link.
            $this->collect($rows, $seen, $invoice->whmcs_invoice_id, $invoice, $isOwn, false, $verifyKind);
        }
    }

    /**
     * Append one issued invoice as a row (de-duped by id). Only LIVE documents are
     * emitted: drafts (not issued) and cancelled / AADE-cancelled (legally void)
     * are skipped via isPubliclyViewable() — the same allow-list the PDF route
     * uses, so the list can never advertise a document the proxy would refuse.
     *
     * @param  array<int, array<string, mixed>>  $rows
     * @param  array<int, true>  $seen
     * @param  bool  $allowPdf  whether the PDF proxy can serve this row (bridge-
     *                          derived yes; historical no — it has no pending row)
     */
    private function collect(array &$rows, array &$seen, ?int $whmcsInvoiceId, Invoice $invoice, bool $isOwn, bool $allowPdf, string $verifyKind): void
    {
        if (isset($seen[$invoice->id])) {
            return;
        }
        if (! $invoice->isPubliclyViewable()) {
            return;   // draft (unissued) or cancelled (void) — not for the customer
        }
        $seen[$invoice->id] = true;

        $verifyUrl = ($invoice->mydata_url !== null && $invoice->mydata_url !== '')
            ? $invoice->mydata_url
            : null;

        $rows[] = [
            'ekdosi_invoice_id' => $invoice->id,
            'whmcs_invoice_id' => $whmcsInvoiceId,
            'issued_at' => $invoice->issued_at?->format('Y-m-d'),
            'invcode' => $invoice->invcode,
            'type' => $invoice->invoiceType?->name,
            'is_own' => $isOwn,
            'party_name' => $invoice->counterpartName(),
            'party_afm' => $invoice->counterpartAfm(),
            'local_status' => $invoice->local_status,
            'mydata_state' => $invoice->mydata_state,
            'mydata_mark' => $invoice->mydata_mark,
            'verify_url' => $verifyUrl,
            // Only meaningful when there's a URL to label.
            'verify_kind' => $verifyUrl !== null ? $verifyKind : null,
            // A historical row is viewable but has no pending row for the proxy to
            // authorize against → no PDF button (the verify link still stands).
            'has_pdf' => $allowPdf,
        ];
    }

    private function verifySignature(Request $request, string $secret): bool
    {
        $header = (string) $request->header('X-Webhook-Signature', '');
        if (! str_starts_with($header, 'sha256=')) {
            return false;
        }
        $sent = substr($header, strlen('sha256='));
        $expected = hash_hmac('sha256', $request->getContent(), $secret);

        return hash_equals($expected, $sent);
    }

    private function logRejection(Request $request, string $slug, string $reason): void
    {
        $sig = (string) $request->header('X-Webhook-Signature', '');
        Log::warning('whmcs.issued-for-client.rejected', [
            'reason' => $reason,
            'slug' => $slug,
            'ip' => $request->ip(),
            'sig_prefix' => $sig === '' ? '<missing>' : substr($sig, 0, 15).'...',
        ]);
    }
}
