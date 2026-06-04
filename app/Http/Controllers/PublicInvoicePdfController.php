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
        // FAIL-CLOSED: only an issued, non-cancelled document is a valid public
        // παραστατικό. Drafts AND cancelled (legally void) AND any unknown status
        // are refused — see Invoice::isPubliclyViewable().
        if (! $invoice->isPubliclyViewable()) {
            abort(Response::HTTP_NOT_FOUND);
        }

        $pdf = $renderer->render($invoice);
        // ASCII fallback restricted to a SAFE charset: invcode = invoice_type.code
        // (operator free-text) + aa, so stripping only non-printables would still
        // let a '"' / '\' / ';' break out of the quoted filename. Keep only
        // alphanumerics/dot/dash/underscore.
        $ascii = ($invoice->invcode !== null && $invoice->invcode !== '')
            ? preg_replace('/[^A-Za-z0-9._-]/', '_', (string) $invoice->invcode)
            : 'invoice-'.$invoice->getKey();
        $utf8 = ($invoice->invcode !== null && $invoice->invcode !== '')
            ? (string) $invoice->invcode
            : 'invoice-'.$invoice->getKey();

        // RFC 5987: an ASCII `filename` fallback + a UTF-8 `filename*` so a Greek
        // invcode (ΑΠΥ423) survives strict proxies/servers instead of corrupting
        // the header or showing mojibake.
        return response($pdf, Response::HTTP_OK, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="'.$ascii.'.pdf"; '
                ."filename*=UTF-8''".rawurlencode($utf8).'.pdf',
        ]);
    }
}
