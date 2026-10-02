<?php

namespace App\Http\Controllers;

use App\Models\Invoice;
use App\Models\User;
use App\Services\InvoicePdfRenderer;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\URL;
use Spatie\Permission\PermissionRegistrar;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

/**
 * The 80mm till receipt (docs/woocommerce-bridge-plan.md §11): a small HTML page
 * the browser prints on the thermal printer (silent with Chrome --kiosk-printing).
 * AUTH + SIGNED + tenant-checked — opened by the «Ταμείο» right after issuing.
 * Same view data as the A4 PDF (QR, ΜΑΡΚ, provider evidence, VAT breakdown) so the
 * legal content can't drift between the two. An exchange prints TWO documents in one
 * run (`?with=` — the return credit note and the new sale), so the browser opens a
 * single window.
 */
class PosReceiptController extends Controller
{
    /** The signed link to a receipt (the till: minutes; the invoice page: a working day). */
    public static function signedUrl(int $invoiceId, int $minutes = 30, ?int $withId = null): string
    {
        return URL::temporarySignedRoute('pos.receipt', now()->addMinutes($minutes), array_filter([
            'invoice' => $invoiceId,
            'with' => $withId,
        ]));
    }

    public function __invoke(Request $request, Invoice $invoice, InvoicePdfRenderer $renderer): Response
    {
        $user = $request->user();
        $this->authorizeDocument($user, $invoice);

        $documents = [$renderer->viewData($invoice)];
        if ($request->filled('with')) {
            $second = Invoice::query()->withoutGlobalScopes()->whereKey((int) $request->query('with'))->firstOrFail();
            abort_unless((int) $second->company_id === (int) $invoice->company_id, HttpResponse::HTTP_NOT_FOUND);
            $this->authorizeDocument($user, $second);
            $documents[] = $renderer->viewData($second);
        }

        return response()
            ->view('pos.receipt', ['documents' => $documents])
            ->header('Cache-Control', 'private, no-store');
    }

    private function authorizeDocument(?User $user, Invoice $invoice): void
    {
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
            // A cashier (View:PointOfSale) prints the TILL's documents only — its sale
            // and return-credit series; any other document needs the invoice permission.
            $tillSeries = array_filter([$invoice->company?->pos_invoice_type_id, $invoice->company?->pos_credit_type_id]);
            $tillDocument = $invoice->invoice_type_id !== null && in_array((int) $invoice->invoice_type_id, array_map('intval', $tillSeries), true);
            abort_unless(
                $user->can('View:Invoice') || ($tillDocument && $user->can('View:PointOfSale')),
                HttpResponse::HTTP_FORBIDDEN,
            );
        } finally {
            $registrar->setPermissionsTeamId($priorTeamId);
        }

        // Only an ISSUED, live document prints as a receipt — never a draft or an
        // AADE-cancelled one.
        abort_unless($invoice->isReceiptPrintable(), HttpResponse::HTTP_NOT_FOUND);
    }
}
