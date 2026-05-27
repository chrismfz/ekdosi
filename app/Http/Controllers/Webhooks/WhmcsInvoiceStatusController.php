<?php

namespace App\Http\Controllers\Webhooks;

use App\Models\Company;
use App\Models\PendingWhmcsInvoice;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * Stage B-3: outbound query endpoint the WHMCS-side ekdosi_bridge
 * plugin uses to render "what does ekdosi know about this invoice"
 * on the WHMCS admin invoice page.
 *
 *   GET /webhooks/whmcs/{slug}/invoice-status/{whmcs_invoice_id}
 *   X-Webhook-Signature: sha256=<hex hmac>
 *
 * Auth: HMAC-SHA256 over the request path (the resolved URL after
 * route matching, including the {slug} and {whmcs_invoice_id}
 * placeholders' actual values). GET has no body to sign, so the
 * path is the next-best canonical input. Hash with the same
 * whmcs_webhook_secret used for inbound POSTs — bidirectional trust
 * on one key.
 *
 * Response shape (200 OK):
 *   {
 *     "found": true,
 *     "status": "pending_review" | "filed" | "rejected" | "held",
 *     "pending_id": 42,
 *     "mydata_mark": "999000111" | null,
 *     "filed_at": "2026-05-28T14:30:00+03:00" | null,
 *     "notes": "Filed at AADE as invoice #ΤΠΥ123 (MARK 999000111).",
 *     "ekdosi_invoice_id": 123 | null
 *   }
 *
 * When no pending row exists for the given WHMCS invoice id:
 *   200 OK { "found": false }
 *
 * 200 + found=false (vs 404) lets the WHMCS-side plugin render a
 * "not yet pushed to ekdosi" badge without having to special-case
 * HTTP error codes. The plugin's UI is a passive status indicator;
 * "no row exists" is a valid state, not an error.
 *
 * Status codes:
 *   200 OK         - status returned (whether found or not)
 *   401 Unauthorized - missing / wrong signature
 *   404 Not Found  - tenant slug doesn't resolve
 *   422 Unprocessable - tenant has no whmcs_webhook_secret configured
 */
class WhmcsInvoiceStatusController
{
    public function __invoke(
        Request $request,
        string $slug,
        int $whmcsInvoiceId,
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
                'error'   => 'webhook_secret_not_configured',
                'message' => 'Tenant exists but has no whmcs_webhook_secret. '
                    .'Set it in the company config form.',
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        // HMAC verification over the request path. Path is the
        // resolved URL (after route placeholder substitution) so a
        // signature over "/webhooks/whmcs/foo/invoice-status/8888"
        // doesn't validate "/webhooks/whmcs/foo/invoice-status/8889".
        if (! $this->verifySignature($request, $secret)) {
            $this->logRejection($request, $slug, 'invalid_signature');
            return new JsonResponse(['error' => 'invalid_signature'], Response::HTTP_UNAUTHORIZED);
        }

        $row = PendingWhmcsInvoice::query()
            ->where('company_id', $tenant->id)
            ->where('whmcs_invoice_id', $whmcsInvoiceId)
            ->first();

        if ($row === null) {
            return new JsonResponse([
                'found'            => false,
                'whmcs_invoice_id' => $whmcsInvoiceId,
            ], Response::HTTP_OK);
        }

        return new JsonResponse([
            'found'             => true,
            'pending_id'        => $row->id,
            'whmcs_invoice_id'  => $whmcsInvoiceId,
            'status'            => $row->status,
            'mydata_mark'       => $row->mydata_mark,
            'filed_at'          => $row->filed_at?->toIso8601String(),
            'rejected_reason'   => $row->rejected_reason,
            'notes'             => $row->notes,
            'ekdosi_invoice_id' => $row->invoice_id,
        ], Response::HTTP_OK);
    }

    /**
     * HMAC-SHA256 verification over the request path (no body in GET).
     * Header format: `X-Webhook-Signature: sha256=<hex>` where
     * <hex> = hash_hmac('sha256', $request->getPathInfo(), $secret).
     *
     * getPathInfo() returns the URL-decoded path WITHOUT scheme/host/
     * query — exactly what the WHMCS-side plugin can reconstruct
     * deterministically from "{webhooks_prefix}/{slug}/invoice-status/
     * {whmcs_invoice_id}".
     */
    private function verifySignature(Request $request, string $secret): bool
    {
        $header = (string) $request->header('X-Webhook-Signature', '');
        if (! str_starts_with($header, 'sha256=')) {
            return false;
        }
        $sent = substr($header, strlen('sha256='));

        $expected = hash_hmac('sha256', $request->getPathInfo(), $secret);

        return hash_equals($expected, $sent);
    }

    private function logRejection(Request $request, string $slug, string $reason): void
    {
        $sig = (string) $request->header('X-Webhook-Signature', '');
        Log::warning('whmcs.status.rejected', [
            'reason'     => $reason,
            'slug'       => $slug,
            'ip'         => $request->ip(),
            'user_agent' => substr((string) $request->userAgent(), 0, 200),
            'sig_prefix' => $sig === '' ? '<missing>' : substr($sig, 0, 15).'...',
        ]);
    }
}
