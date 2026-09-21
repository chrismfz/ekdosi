<?php

namespace App\Http\Controllers\Portal;

use App\Http\Controllers\Controller;
use App\Models\Invoice;
use App\Models\Scopes\CompanyScope;
use App\Services\Portal\CustomerDocumentFeed;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\Response;

/**
 * Read-only HTML «online προβολή» of ONE of the customer's own documents — a
 * reference view (like the WHMCS client area), NOT a legal artifact (the official
 * PDF stays that). FAIL-CLOSED with the exact same boundary as the PDF route:
 * the document must be customer-visible AND reachable through one of this login's
 * active grants, else a flat 404 (no existence disclosure).
 *
 * It also surfaces the LINKED documents — the original a credit note credits, the
 * credit notes issued against an invoice, and the receipts/refunds booked on it —
 * each link re-gated through the same predicate so it can never point at a document
 * this login may not open. (The Service↔document link drops in here later.)
 */
class DocumentShowController extends Controller
{
    public function __invoke(Request $request, int $invoice, CustomerDocumentFeed $feed): View
    {
        $login = Auth::guard('portal')->user();

        // No ambient tenant in the portal, but declare the cross-company intent.
        $model = Invoice::query()
            ->withoutGlobalScope(CompanyScope::class)
            ->with([
                'company',
                'customer',
                'invoiceType',
                'lines',
                'paymentMethod',
                'payments' => fn ($q) => $q->orderBy('pay_date')->orderBy('id'),
                'payments.paymentMethod',
                'creditedInvoice.invoiceType',
                'creditNotes' => fn ($q) => $q->orderByDesc('issued_at')->orderByDesc('id'),
                'creditNotes.invoiceType',
            ])
            ->whereKey($invoice)
            ->first();

        if ($model === null || ! $feed->loginCanAccess($login, $model)) {
            abort(Response::HTTP_NOT_FOUND);
        }

        // Linked documents are gated so a link never points at something this login
        // can't open (a related doc could be cancelled/draft). They share this
        // invoice's (company, customer) — a credit note and its original are issued
        // to the same customer — and the main gate above already proved an active
        // grant for that party, so a linked doc is openable iff it's customer-visible
        // for the SAME party. That avoids re-running the grant query per credit note
        // (an N+1) while staying exactly as strict as loginCanAccess.
        $sameGrantedParty = fn (Invoice $rel): bool => $rel->isCustomerVisible()
            && (int) $rel->company_id === (int) $model->company_id
            && $rel->customer_id !== null
            && (int) $rel->customer_id === (int) $model->customer_id;

        $original = ($model->creditedInvoice !== null && $sameGrantedParty($model->creditedInvoice))
            ? $model->creditedInvoice
            : null;

        $creditNotes = $model->creditNotes
            ->filter($sameGrantedParty)
            ->values();

        return view('portal.document', [
            'invoice' => $model,
            'balance' => $model->balanceData(),
            'original' => $original,
            'creditNotes' => $creditNotes,
        ]);
    }
}
