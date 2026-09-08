<?php

namespace App\Http\Controllers\Webhooks;

use App\Models\Company;
use App\Models\Invoice;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * Deterministic HISTORICAL lookup keyed by legacy id — the WHMCS-side badges
 * that light up already-filed (imported) invoices.
 *
 * The legacy auto-invoicer (FAutoInvoice.cpp) wrote the legacy ekdosi
 * INVOICE_ID into WHMCS `tblinvoices.invoiced`; the ETL preserved that same
 * INVOICE_ID as `invoices.legacy_id`. So `tblinvoices.invoiced ===
 * invoices.legacy_id` is an EXACT foreign key, not the heuristic content-match
 * that was (rightly) removed. The plugin sends the `invoiced` values of the
 * WHMCS invoices it's showing and gets ΤΠΥ + ΜΑΡΚ back — covering the thousands
 * of imported invoices with no re-import and no ΑΦΜ guessing.
 *
 *   POST /webhooks/whmcs/{slug}/invoices-by-legacy-id
 *   X-Webhook-Signature: sha256=<hex hmac of raw body>
 *   {"legacy_ids": [7677, 7680, ...]}
 *
 * Auth: HMAC-SHA256 over the RAW body, same whmcs_webhook_secret as the other
 * webhooks. Read-only, tenant-scoped, capped.
 *
 * Response (200 OK):
 *   {
 *     "invoices": {
 *       "7677": {
 *         "ekdosi_invoice_id": 6753, "ekdosi_invcode": "ΤΠΥ6642",
 *         "local_status": "active", "mydata_state": "VALID",
 *         "mydata_mark": "400013718620394", "whmcs_invoice_id": 31618
 *       },
 *       "7680": null
 *     }
 *   }
 *
 * A legacy id with no matching invoice (created in ekdosi post-cutover, or a
 * sentinel like -1000/-333 the plugin shouldn't send) returns null.
 */
class WhmcsInvoicesByLegacyIdController
{
    /** Max legacy ids accepted per request (one list page). */
    private const MAX_IDS = 500;

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

        $ids = $this->normaliseIds($request->json('legacy_ids'));
        if ($ids === []) {
            return new JsonResponse([
                'error' => 'missing_or_invalid_ids',
                'message' => 'Body must be {"legacy_ids": [<int>, ...]} with at least one positive id.',
            ], Response::HTTP_BAD_REQUEST);
        }

        // One tenant-scoped query, keyed by legacy_id.
        $rows = Invoice::query()
            ->where('company_id', $tenant->id)
            ->whereIn('legacy_id', $ids)
            ->get(['id', 'legacy_id', 'invcode', 'local_status', 'mydata_state', 'mydata_mark', 'whmcs_invoice_id'])
            ->keyBy('legacy_id');

        $out = [];
        foreach ($ids as $id) {
            $inv = $rows->get($id);
            $out[$id] = $inv === null ? null : [
                'ekdosi_invoice_id' => $inv->id,
                'ekdosi_invcode' => $inv->invcode,
                'local_status' => $inv->local_status,
                'mydata_state' => $inv->mydata_state,
                'mydata_mark' => $inv->mydata_mark,
                'whmcs_invoice_id' => $inv->whmcs_invoice_id,
            ];
        }

        return new JsonResponse(['invoices' => $out], Response::HTTP_OK);
    }

    /**
     * Clean the inbound id list: ints > 0, de-duped, capped. Negative sentinels
     * (-1000 / -333 / -1) and zero are dropped — they are not legacy ids.
     *
     * @return list<int>
     */
    private function normaliseIds(mixed $raw): array
    {
        if (! is_array($raw)) {
            return [];
        }

        $clean = [];
        foreach ($raw as $value) {
            if (! is_int($value) && ! (is_string($value) && ctype_digit($value))) {
                continue;
            }
            $id = (int) $value;
            if ($id <= 0) {
                continue;
            }
            $clean[$id] = true;   // de-dupe via keys
            if (count($clean) >= self::MAX_IDS) {
                break;
            }
        }

        return array_keys($clean);
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
        Log::warning('whmcs.invoices-by-legacy-id.rejected', [
            'reason' => $reason,
            'slug' => $slug,
            'ip' => $request->ip(),
            'sig_prefix' => $sig === '' ? '<missing>' : substr($sig, 0, 15).'...',
        ]);
    }
}
