<?php

namespace App\Http\Controllers;

use App\Models\Invoice;
use App\Services\InvoicePdfRenderer;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Spatie\Permission\PermissionRegistrar;
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
        abort_unless($user !== null && $user->companies()->whereKey($invoice->company_id)->exists(), HttpResponse::HTTP_FORBIDDEN);

        // This route is OUTSIDE the Filament panel, so the TenantSet listener that
        // syncs Spatie's teams team-id never fired — set it to the invoice's
        // (already membership-checked) company for the permission check, else a
        // non-super-admin cashier's team-scoped role never matches (null team-id).
        // Restored in a finally (as TicketAttachmentController).
        $registrar = app(PermissionRegistrar::class);
        $priorTeamId = $registrar->getPermissionsTeamId();
        $registrar->setPermissionsTeamId($invoice->company_id);
        try {
            // A cashier (View:PointOfSale) prints the TILL's receipts only; any other
            // document of the company needs the invoice permission.
            $tillReceipt = $invoice->invoice_type_id !== null
                && (int) $invoice->invoice_type_id === (int) $invoice->company?->pos_invoice_type_id;
            abort_unless(
                $user->can('View:Invoice') || ($tillReceipt && $user->can('View:PointOfSale')),
                HttpResponse::HTTP_FORBIDDEN,
            );
        } finally {
            $registrar->setPermissionsTeamId($priorTeamId);
        }

        // Only an ISSUED, live document prints as a receipt — never a draft or an
        // AADE-cancelled one.
        abort_if(
            $invoice->local_status !== 'active' || $invoice->code === null || $invoice->mydata_state === 'CANCELLED',
            HttpResponse::HTTP_NOT_FOUND,
        );

        return response()
            ->view('pos.receipt', $renderer->viewData($invoice))
            ->header('Cache-Control', 'private, no-store');
    }
}
