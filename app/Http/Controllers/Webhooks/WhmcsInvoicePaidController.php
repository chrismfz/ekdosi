<?php

namespace App\Http\Controllers\Webhooks;

use App\Exceptions\Whmcs\WhmcsApiException;
use App\Exceptions\Whmcs\WhmcsAuthenticationFailed;
use App\Exceptions\Whmcs\WhmcsNotConfigured;
use App\Exceptions\Whmcs\WhmcsUnreachable;
use App\Models\Company;
use App\Services\Whmcs\WhmcsClientFactory;
use App\Services\Whmcs\WhmcsInvoiceIngestor;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * PR #31 (Stage B-1): inbound webhook for the WHMCS-side plugin to
 * announce "this invoice is paid + ready for ekdosi to file".
 *
 *   POST /webhooks/whmcs/{slug}/invoice-paid
 *   X-Webhook-Signature: sha256=<hex hmac of raw body>
 *   {"whmcs_invoice_id": 12345}
 *
 * Body shape: a JSON object with the WHMCS invoice id. We DON'T
 * accept the full invoice payload over the wire - WHMCS-side push
 * bodies can be inspected (proxy logs, ngrok, paste-bins) and we
 * don't want customer data leaking via a misconfigured intermediary.
 * The ID alone is enough: we use our own outbound API credentials
 * to fetch the canonical payload from WHMCS, then stage it.
 *
 * Status codes:
 *   202 Accepted - new pending_whmcs_invoices row staged
 *   200 OK       - existing row updated (or audit-preserved if filed)
 *   400          - malformed body / missing whmcs_invoice_id
 *   401          - missing / wrong / unverifiable signature
 *   404          - tenant slug doesn't resolve
 *   409          - WHMCS doesn't recognise this invoice id
 *   422          - tenant has no whmcs_webhook_secret configured
 *   500          - WHMCS-side error / our DB write failed
 *   502          - WHMCS unreachable / authentication failed
 *
 * The split between 401 (signature) and 422 (no secret configured)
 * is deliberate: 401 means "you sent a signature but I can't verify
 * it - look at your secret rotation", 422 means "you sent a signature
 * but I never had a secret to compare against - look at your tenant
 * config".
 */
class WhmcsInvoicePaidController
{
    public function __invoke(
        Request $request,
        string $slug,
        WhmcsClientFactory $factory,
        WhmcsInvoiceIngestor $ingestor,
    ): JsonResponse {
        $tenant = Company::query()->where('slug', $slug)->first();
        if ($tenant === null) {
            return $this->json(['error' => 'tenant_not_found'], Response::HTTP_NOT_FOUND);
        }

        // Verify the signature BEFORE touching the body or invoking
        // any downstream service. A failed verification must not
        // produce any side effects (no DB query past the tenant
        // lookup, no outbound WHMCS call).
        $secret = (string) ($tenant->whmcs_webhook_secret ?? '');
        if ($secret === '') {
            return $this->json([
                'error'   => 'webhook_secret_not_configured',
                'message' => 'Tenant exists but has no whmcs_webhook_secret. '
                    .'Set it in the company config form.',
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        if (! $this->verifySignature($request, $secret)) {
            return $this->json([
                'error' => 'invalid_signature',
            ], Response::HTTP_UNAUTHORIZED);
        }

        $body = $request->json()->all();
        $whmcsInvoiceId = (int) ($body['whmcs_invoice_id'] ?? 0);
        if ($whmcsInvoiceId <= 0) {
            return $this->json([
                'error'   => 'missing_or_invalid_whmcs_invoice_id',
                'message' => 'Body must be {"whmcs_invoice_id": <int>}.',
            ], Response::HTTP_BAD_REQUEST);
        }

        try {
            $client = $factory->for($tenant);
        } catch (WhmcsNotConfigured $e) {
            // Tenant has a webhook secret but no API credentials -
            // we can verify the inbound push but can't fetch the
            // invoice payload to stage it. Treat as 422.
            return $this->json([
                'error'   => 'whmcs_api_not_configured',
                'message' => $e->getMessage(),
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        try {
            $payload = $client->getInvoice($whmcsInvoiceId);
        } catch (WhmcsAuthenticationFailed | WhmcsUnreachable $e) {
            // WHMCS-side transient / config failures. 502 Bad Gateway
            // is the right semantic: WE are reachable, the upstream
            // we depend on is not. WHMCS-side retry logic can back off.
            return $this->json([
                'error'   => 'whmcs_upstream_failure',
                'message' => $e->getMessage(),
            ], Response::HTTP_BAD_GATEWAY);
        } catch (WhmcsApiException $e) {
            return $this->json([
                'error'   => 'whmcs_api_error',
                'message' => $e->getMessage(),
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }

        if ($payload === null) {
            // WHMCS knows the tenant but doesn't recognise this
            // invoice id. Could be a race (invoice deleted post-push)
            // or a typo on the WHMCS-side plugin. 409 Conflict
            // signals "your input contradicts the upstream state".
            return $this->json([
                'error'            => 'whmcs_invoice_not_found',
                'whmcs_invoice_id' => $whmcsInvoiceId,
            ], Response::HTTP_CONFLICT);
        }

        $result = $ingestor->ingest($tenant, $payload);

        return $this->json([
            'pending_whmcs_invoice_id' => $result->row->id,
            'whmcs_invoice_id'         => $whmcsInvoiceId,
            'status'                   => $result->row->status,
            'created'                  => $result->created,
            'audit_preserved'          => $result->auditPreserved,
        ], $result->created ? Response::HTTP_ACCEPTED : Response::HTTP_OK);
    }

    /**
     * HMAC-SHA256 verification with constant-time comparison.
     *
     * Header format: `X-Webhook-Signature: sha256=<hex>` where
     * <hex> = hash_hmac('sha256', $rawRequestBody, $secret).
     *
     * Uses the raw request body (NOT the json-decoded payload) so
     * whitespace / key-order differences between WHMCS-side
     * serialisation and our parser cannot break verification.
     */
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

    /**
     * Force application/json regardless of accept header. Keeps the
     * WHMCS-side plugin simple - it can always parse the response as
     * JSON without negotiating content type.
     */
    private function json(array $body, int $status): JsonResponse
    {
        return new JsonResponse($body, $status);
    }
}
