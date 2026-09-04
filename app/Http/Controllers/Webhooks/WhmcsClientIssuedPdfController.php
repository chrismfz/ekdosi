<?php

namespace App\Http\Controllers\Webhooks;

use App\Models\Company;
use App\Models\Invoice;
use App\Models\PendingWhmcsInvoice;
use App\Services\InvoicePdfRenderer;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * Customer-facing (via WHMCS): stream ONE issued invoice's official PDF to the
 * WHMCS plugin, which proxies the bytes to the logged-in reseller. Backs the
 * «PDF» button on the "Εκδοθέντα Παραστατικά" page.
 *
 *   GET /webhooks/whmcs/{slug}/issued-doc-pdf/{whmcs_userid}/{invoice}
 *   X-Webhook-Signature: sha256=<hex hmac of
 *                        "{slug}:issued-pdf:{whmcs_userid}:{invoice_id}">
 *
 * Why a proxy over the HMAC channel (the operator's choice) instead of handing
 * out the signed public URL: the raw bearer link then never reaches the
 * customer's browser (no leak via history/referer/forwarded mail), AND the
 * authorization is re-derived HERE on every request — the reseller cannot reach
 * an invoice outside the set produced from WHMCS invoices they paid, even by
 * guessing an id. Three fail-closed checks, each → 404:
 *   1. the invoice belongs to this tenant,
 *   2. it is publicly viewable (issued, non-cancelled — same allow-list as the
 *      signed public route),
 *   3. it is reachable from a pending row with this whmcs_userid (own OR routed).
 *
 * The signed public route (PublicInvoicePdfController) is untouched — it still
 * backs the legacy per-invoice admin/client button. This endpoint is the
 * dedicated, membership-checked path for the customer list.
 *
 * Auth mirrors the sibling issued-for-client endpoint: HMAC-SHA256 over a
 * canonical string; the "issued-pdf:" infix (with the invoice id bound in) keeps
 * a PDF signature from being replayable as a list one, or across invoices.
 *
 * The invoice id is taken as a PLAIN int (NOT an implicitly-bound model) so the
 * signature is verified BEFORE any DB lookup: an unauthenticated caller always
 * gets 401, whether or not the id exists — no existence oracle across tenants.
 */
class WhmcsClientIssuedPdfController
{
    public function __invoke(
        Request $request,
        string $slug,
        int $whmcsUserid,
        int $invoice,
        InvoicePdfRenderer $renderer,
    ): Response {
        $tenant = Company::query()->where('slug', $slug)->first();
        if ($tenant === null) {
            $this->logRejection($request, $slug, 'tenant_not_found');
            abort(Response::HTTP_NOT_FOUND);
        }

        $secret = (string) ($tenant->whmcs_webhook_secret ?? '');
        if ($secret === '') {
            $this->logRejection($request, $slug, 'webhook_secret_not_configured');
            abort(Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $canonical = $slug.':issued-pdf:'.$whmcsUserid.':'.$invoice;
        if (! $this->verifySignature($request, $secret, $canonical)) {
            $this->logRejection($request, $slug, 'invalid_signature');
            abort(Response::HTTP_UNAUTHORIZED);
        }

        // Load only AFTER the signature passes. FAIL-CLOSED authorization: any
        // failure is a flat 404 — never reveal whether the id exists, belongs to
        // another tenant, or is out of scope. (The CompanyScope no-ops without
        // panel context, so scope by company_id explicitly.)
        $model = Invoice::query()
            ->where('company_id', $tenant->id)
            ->whereKey($invoice)
            ->first();
        if ($model === null) {
            abort(Response::HTTP_NOT_FOUND);
        }
        if (! $model->isPubliclyViewable()) {
            abort(Response::HTTP_NOT_FOUND);
        }
        if (! $this->reachableByReseller($tenant, $whmcsUserid, $model)) {
            abort(Response::HTTP_NOT_FOUND);
        }

        $pdf = $renderer->render($model);

        // ASCII fallback restricted to a SAFE charset (invcode is operator
        // free-text): keep only alphanumerics/dot/dash/underscore so nothing can
        // break out of the quoted filename. Mirrors PublicInvoicePdfController.
        $ascii = ($model->invcode !== null && $model->invcode !== '')
            ? preg_replace('/[^A-Za-z0-9._-]/', '_', (string) $model->invcode)
            : 'invoice-'.$model->getKey();
        $utf8 = ($model->invcode !== null && $model->invcode !== '')
            ? (string) $model->invcode
            : 'invoice-'.$model->getKey();

        return response($pdf, Response::HTTP_OK, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="'.$ascii.'.pdf"; '
                ."filename*=UTF-8''".rawurlencode($utf8).'.pdf',
        ]);
    }

    /**
     * Is this invoice reachable from a WHMCS invoice the reseller paid — either
     * as the 1:1 filed document (pending.invoice_id) or as a split part
     * (invoices.whmcs_pending_id)? This is the exact membership the list endpoint
     * enforces, re-checked per PDF request.
     */
    private function reachableByReseller(Company $tenant, int $whmcsUserid, Invoice $invoice): bool
    {
        return PendingWhmcsInvoice::query()
            ->where('company_id', $tenant->id)
            ->where('whmcs_userid', $whmcsUserid)
            ->where(function ($q) use ($invoice): void {
                $q->where('invoice_id', $invoice->getKey())
                    ->orWhereHas('splitInvoices', fn ($s) => $s->whereKey($invoice->getKey()));
            })
            ->exists();
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
        Log::warning('whmcs.issued-doc-pdf.rejected', [
            'reason' => $reason,
            'slug' => $slug,
            'ip' => $request->ip(),
            'sig_prefix' => $sig === '' ? '<missing>' : substr($sig, 0, 15).'...',
        ]);
    }
}
