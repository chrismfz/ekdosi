<?php

namespace App\Http\Controllers;

use App\Models\Invoice;
use App\Services\InvoicePdfRenderer;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

/**
 * The 80mm till receipt (docs/woocommerce-bridge-plan.md §11): a small HTML page
 * the browser prints on the thermal printer (silent with Chrome --kiosk-printing).
 * AUTH + SIGNED + tenant-checked — opened by the «Ταμείο» right after issuing.
 * Same view data as the A4 PDF (QR, ΜΑΡΚ, provider evidence, VAT breakdown) so the
 * legal content can't drift between the two.
 */
class PosReceiptController extends Controller
{
    public function __invoke(Request $request, Invoice $invoice, InvoicePdfRenderer $renderer): Response
    {
        $user = $request->user();
        abort_unless($user !== null && ($user->can('View:PointOfSale') || $user->can('View:Invoice')), HttpResponse::HTTP_FORBIDDEN);
        abort_unless($user->companies()->whereKey($invoice->company_id)->exists(), HttpResponse::HTTP_FORBIDDEN);
        // Only an ISSUED document prints as a receipt — never a draft.
        abort_if($invoice->local_status !== 'active' || $invoice->code === null, HttpResponse::HTTP_NOT_FOUND);

        return response()
            ->view('pos.receipt', $renderer->viewData($invoice))
            ->header('Cache-Control', 'private, no-store');
    }
}
