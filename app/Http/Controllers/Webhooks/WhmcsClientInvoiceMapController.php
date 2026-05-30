<?php

namespace App\Http\Controllers\Webhooks;

use App\Models\Company;
use App\Models\PendingWhmcsInvoice;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * Visibility: the 3-way mapping the WHMCS-side ekdosi_bridge plugin renders on
 * a client's admin profile — for every WHMCS invoice of that client that ekdosi
 * knows about, return WHMCS # → ekdosi παραστατικό (invcode) → ΜΑΡΚ + state.
 *
 *   GET /webhooks/whmcs/{slug}/invoice-map/{whmcs_userid}
 *   X-Webhook-Signature: sha256=<hex hmac of "{slug}:map:{whmcs_userid}">
 *
 * Unlike the per-invoice status endpoint, this is keyed by the WHMCS client id
 * and returns ALL their rows — including DRAFTS that have an ekdosi invoice but
 * no MARK yet ("μισό visibility": a παραστατικό exists, just not filed at AADE).
 *
 * Auth mirrors WhmcsInvoiceStatusController: HMAC-SHA256 over a canonical
 * string (GET has no body), same whmcs_webhook_secret. The "map:" infix keeps
 * a map signature from being interchangeable with a status one.
 *
 * Response (200 OK):
 *   {
 *     "found": true,
 *     "whmcs_userid": 793,
 *     "rows": [
 *       {
 *         "whmcs_invoice_id": 31588,
 *         "pending_status": "drafted" | "filed" | "pending_review" | ...,
 *         "ekdosi_invoice_id": 129 | null,
 *         "ekdosi_invcode": "ΤΠΥ129" | null,
 *         "local_status": "draft" | "active" | "cancelled" | null,
 *         "mydata_state": "VALID" | "CANCELLED" | null,
 *         "mydata_mark": "400..." | null
 *       }, ...
 *     ]
 *   }
 */
class WhmcsClientInvoiceMapController
{
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

        $canonical = $slug.':map:'.$whmcsUserid;
        if (! $this->verifySignature($request, $secret, $canonical)) {
            $this->logRejection($request, $slug, 'invalid_signature');

            return new JsonResponse(['error' => 'invalid_signature'], Response::HTTP_UNAUTHORIZED);
        }

        // The linked invoice carries the ekdosi-side truth (invcode + the two
        // orthogonal statuses + MARK). Eager-load it; a row with invoice_id
        // null is one we haven't turned into a παραστατικό yet.
        $rows = PendingWhmcsInvoice::query()
            ->where('company_id', $tenant->id)
            ->where('whmcs_userid', $whmcsUserid)
            ->with('invoice:id,invcode,local_status,mydata_state,mydata_mark')
            ->orderByDesc('whmcs_invoice_id')
            ->get()
            ->map(fn (PendingWhmcsInvoice $r): array => [
                'whmcs_invoice_id' => $r->whmcs_invoice_id,
                'pending_status' => $r->status,
                'ekdosi_invoice_id' => $r->invoice?->id,
                'ekdosi_invcode' => $r->invoice?->invcode,
                'local_status' => $r->invoice?->local_status,
                'mydata_state' => $r->invoice?->mydata_state,
                // Prefer the invoice's MARK; fall back to the pending row's
                // cached MARK (set by the direct-file path).
                'mydata_mark' => $r->invoice?->mydata_mark ?? $r->mydata_mark,
            ])
            ->all();

        return new JsonResponse([
            'found' => true,
            'whmcs_userid' => $whmcsUserid,
            'rows' => $rows,
        ], Response::HTTP_OK);
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
        Log::warning('whmcs.invoice-map.rejected', [
            'reason' => $reason,
            'slug' => $slug,
            'ip' => $request->ip(),
            'sig_prefix' => $sig === '' ? '<missing>' : substr($sig, 0, 15).'...',
        ]);
    }
}
