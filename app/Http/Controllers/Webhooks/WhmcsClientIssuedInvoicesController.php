<?php

namespace App\Http\Controllers\Webhooks;

use App\Models\Company;
use App\Models\Invoice;
use App\Models\PendingWhmcsInvoice;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * Customer-facing (via WHMCS): the "Εκδοθέντα Παραστατικά" list a reseller sees
 * in their WHMCS client area. Returns every ekdosi παραστατικό that was produced
 * from a WHMCS invoice THIS reseller paid — their own documents AND the ones
 * routed to third parties they set up in «Παραστατικά σε τρίτους (v2)».
 *
 *   GET /webhooks/whmcs/{slug}/issued-for-client/{whmcs_userid}
 *   X-Webhook-Signature: sha256=<hex hmac of "{slug}:issued:{whmcs_userid}">
 *
 * Why this is leak-proof (the (B) decision — «και τα τρίτων που δρομολόγησε»):
 * the authorization boundary is NOT the third party's ΑΦΜ (that party may buy
 * elsewhere too — an ΑΦΜ match would expose unrelated invoices). It is the set
 * of ekdosi invoices reachable from `pending_whmcs_invoices.whmcs_userid =
 * {whmcs_userid}` — i.e. documents produced from invoices the reseller himself
 * paid. A split maps one WHMCS invoice to N ekdosi invoices (own part + one per
 * routed party); the WHMCS-side mark store is 1:1 and cannot represent that, so
 * ekdosi is the authority here and computes the set from the split linkage.
 *
 * Read-only. Only ISSUED documents are returned (drafts are hidden — an unissued
 * document is not a παραστατικό yet). `verify_url` is the AADE/πάροχος public
 * verification link (invoices.mydata_url); `has_pdf` says whether the official
 * PDF can be streamed (via the sibling issued-doc-pdf endpoint). The raw signed
 * PDF URL is deliberately NOT returned — the WHMCS plugin proxies the bytes so
 * an unguessable bearer link never reaches the customer's browser.
 *
 * Auth mirrors WhmcsClientInvoiceMapController: HMAC-SHA256 over a canonical
 * string (GET has no body), same whmcs_webhook_secret. The "issued:" infix keeps
 * this signature from being interchangeable with a map/status one.
 *
 * Response (200 OK):
 *   {
 *     "found": true,
 *     "whmcs_userid": 793,
 *     "rows": [
 *       {
 *         "ekdosi_invoice_id": 129,
 *         "whmcs_invoice_id": 31588,
 *         "issued_at": "2026-08-14",
 *         "invcode": "ΑΠΥ423",
 *         "type": "Απόδειξη Παροχής Υπηρεσιών",
 *         "is_own": true,
 *         "party_name": "NEXON O.E.",
 *         "party_afm": "801280908",
 *         "local_status": "active",
 *         "mydata_state": "VALID",
 *         "mydata_mark": "400...",
 *         "verify_url": "https://...",
 *         "has_pdf": true
 *       }, ...
 *     ]
 *   }
 */
class WhmcsClientIssuedInvoicesController
{
    /**
     * Upper bound on WHMCS invoices (pending rows) folded into one list. Feeds a
     * single unpaginated client-area table; a real reseller is far below this,
     * and the cap keeps a pathological history from blowing up memory/latency.
     */
    private const MAX_PENDING_ROWS = 500;

    public function __invoke(
        Request $request,
        string $slug,
        int $whmcsUserid,
    ): JsonResponse {
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

        $canonical = $slug.':issued:'.$whmcsUserid;
        if (! $this->verifySignature($request, $secret, $canonical)) {
            $this->logRejection($request, $slug, 'invalid_signature');

            return new JsonResponse(['error' => 'invalid_signature'], Response::HTTP_UNAUTHORIZED);
        }

        // Cap the rows loaded: a long-standing reseller can accumulate many WHMCS
        // invoices, and this feeds a single unpaginated client-area table. Newest
        // first, bounded — full pagination is a follow-up (docs/BACKLOG.md). The
        // customer relation is eager-loaded because counterpartName()/Afm() fall
        // back to the live customer when the invoice's party snapshot is blank
        // (mydata-off / legacy rows), which would otherwise be an N+1.
        $pendings = PendingWhmcsInvoice::query()
            ->where('company_id', $tenant->id)
            ->where('whmcs_userid', $whmcsUserid)
            ->with([
                'invoice.invoiceType:id,name',
                'invoice.customer:id,name',
                'splitInvoices.invoiceType:id,name',
                'splitInvoices.customer:id,name',
            ])
            ->orderByDesc('id')
            ->limit(self::MAX_PENDING_ROWS)
            ->get();

        $rows = [];
        $seen = [];
        foreach ($pendings as $pending) {
            // Two links cover both issue paths: the 1:1 invoice_id (direct
            // file() path — always the reseller's own document) and the N split
            // parts (whmcs_pending_id — own part + one per routed third party).
            if ($pending->invoice !== null) {
                $this->collect($rows, $seen, $pending, $pending->invoice, true);
            }
            foreach ($pending->splitInvoices as $split) {
                // A split part is "own" when its counterpart is the same ekdosi
                // customer the WHMCS invoice matched to (the reseller); routed
                // parts carry the third party's customer_id. The comparison holds
                // for null === null too — the reseller's OWN retail part (Απόδειξη,
                // no linked customer) on an unmatched pending stays «Στο όνομά μου»
                // rather than being mislabeled as third-party.
                $isOwn = $split->customer_id === $pending->customer_id;
                $this->collect($rows, $seen, $pending, $split, $isOwn);
            }
        }

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
     * Append one issued invoice as a row (de-duped by id — an invoice could be
     * reachable via both links). Drafts are skipped: an unissued document is not
     * a παραστατικό the customer should see.
     *
     * @param  array<int, array<string, mixed>>  $rows
     * @param  array<int, true>  $seen
     */
    private function collect(array &$rows, array &$seen, PendingWhmcsInvoice $pending, Invoice $invoice, bool $isOwn): void
    {
        if (isset($seen[$invoice->id])) {
            return;
        }
        if ($invoice->local_status === 'draft') {
            return;   // not issued yet — hide from the customer
        }
        $seen[$invoice->id] = true;

        $rows[] = [
            'ekdosi_invoice_id' => $invoice->id,
            'whmcs_invoice_id' => $pending->whmcs_invoice_id,
            'issued_at' => $invoice->issued_at?->format('Y-m-d'),
            'invcode' => $invoice->invcode,
            'type' => $invoice->invoiceType?->name,
            'is_own' => $isOwn,
            'party_name' => $invoice->counterpartName(),
            'party_afm' => $invoice->counterpartAfm(),
            'local_status' => $invoice->local_status,
            'mydata_state' => $invoice->mydata_state,
            'mydata_mark' => $invoice->mydata_mark,
            'verify_url' => ($invoice->mydata_url !== null && $invoice->mydata_url !== '')
                ? $invoice->mydata_url
                : null,
            'has_pdf' => $invoice->isPubliclyViewable(),
        ];
    }

    private function verifySignature(Request $request, string $secret, string $canonical): bool
    {
        $header = (string) $request->header('X-Webhook-Signature', '');
        if (! str_starts_with($header, 'sha256=')) {
            return false;
        }
        $sent = substr($header, strlen('sha256='));
        $expected = hash_hmac('sha256', $canonical, $secret);

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
