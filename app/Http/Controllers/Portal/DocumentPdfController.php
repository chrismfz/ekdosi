<?php

namespace App\Http\Controllers\Portal;

use App\Http\Controllers\Controller;
use App\Models\Invoice;
use App\Models\Scopes\CompanyScope;
use App\Services\InvoicePdfRenderer;
use App\Services\Portal\CustomerDocumentFeed;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Streams one invoice's official PDF to the logged-in customer. Because the
 * viewer is authenticated in-app, we serve the bytes directly (no signed URL) —
 * FAIL-CLOSED: the document must be live AND reachable through one of this
 * login's active grants, else a flat 404 (no existence disclosure).
 */
class DocumentPdfController extends Controller
{
    public function __invoke(
        Request $request,
        int $invoice,
        CustomerDocumentFeed $feed,
        InvoicePdfRenderer $renderer,
    ): Response {
        // No ambient tenant in the portal, but declare the cross-company intent.
        $model = Invoice::query()
            ->withoutGlobalScope(CompanyScope::class)
            ->whereKey($invoice)
            ->first();

        if ($model === null || ! $feed->loginCanAccess(Auth::guard('portal')->user(), $model)) {
            abort(Response::HTTP_NOT_FOUND);
        }

        $pdf = $renderer->render($model);

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
}
