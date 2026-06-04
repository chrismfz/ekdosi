<?php

namespace App\Http\Controllers;

use App\Models\Invoice;
use App\Services\InvoicePdfRenderer;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Public, AUTH-LESS but SIGNED route that streams an invoice's PDF — the
 * "official παραστατικό (ΑΑΔΕ)" link the WHMCS bridge surfaces. The PDF lives
 * HERE (ekdosi is the source of truth, QR/MARK always current); WHMCS only holds
 * an unguessable signed URL (HMAC over the URL with APP_KEY, via the `signed`
 * middleware) — no PDF copy, no shared storage.
 *
 * Security: the `signed` middleware rejects any tampered/forged URL (403). We
 * additionally refuse drafts (404) so an unissued document can never leak, and
 * we expose ONLY the PDF — no other invoice data, no list, no enumeration (the
 * signature is per-invoice and unforgeable). No tenant context here, so the
 * CompanyScope global scope no-ops and the bound id resolves directly.
 */
class PublicInvoicePdfController extends Controller
{
    public function __invoke(Request $request, Invoice $invoice, InvoicePdfRenderer $renderer): Response
    {
        // Never serve a draft — only issued (active/cancelled) documents have a
        // legal παραστατικό to show.
        if ($invoice->local_status === 'draft') {
            abort(Response::HTTP_NOT_FOUND);
        }

        $pdf = $renderer->render($invoice);
        $filename = ($invoice->invcode !== null && $invoice->invcode !== '')
            ? $invoice->invcode.'.pdf'
            : 'invoice-'.$invoice->getKey().'.pdf';

        return response($pdf, Response::HTTP_OK, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="'.$filename.'"',
        ]);
    }
}
