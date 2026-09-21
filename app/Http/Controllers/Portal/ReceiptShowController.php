<?php

namespace App\Http\Controllers\Portal;

use App\Http\Controllers\Controller;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\Scopes\CompanyScope;
use App\Services\Payments\PaymentGatewayRegistry;
use App\Services\Portal\CustomerDocumentFeed;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\Response;

/**
 * Read-only «informal receipt» view of a payment / refund / έμβασμα — the money
 * side of the ledger, made visible: date, amount, channel («από πού ήρθε» —
 * Πύλη/Eurobank / χειροκίνητα), method, bank account, transaction id, reference,
 * the operator's αιτιολογία (notes), and which invoice(s) it settled. NOT a tax
 * document (that is the invoice PDF) — a reference the customer can open from the
 * statement, mirroring the invoice online view.
 *
 * FAIL-CLOSED: the payment must belong to a (company, customer) this login holds an
 * active grant for, else a flat 404. A referenced payment re-expands to its whole
 * έμβασμα group (all payments sharing that reference for the same party), so the
 * grouped statement row and this page show the same money event. Provenance shown
 * for a group is only the values ALL members share (a field that differs is omitted,
 * never a single member's value attributed to the whole sum).
 */
class ReceiptShowController extends Controller
{
    public function __invoke(
        Request $request,
        int $payment,
        CustomerDocumentFeed $feed,
        PaymentGatewayRegistry $gateways,
    ): View {
        $login = Auth::guard('portal')->user();

        $model = Payment::query()
            ->withoutGlobalScope(CompanyScope::class)
            ->with(['company', 'customer', 'paymentMethod', 'bankAccount', 'paymentIntent'])
            ->whereKey($payment)
            ->first();

        if ($model === null
            || $model->customer_id === null
            // A zero/negative-amount payment is a legacy ETL artifact the ledger
            // deliberately drops (#377) — it never appears as a statement link, so a
            // deep link to one is 404, not a bogus €0 receipt.
            || (float) $model->amount <= 0
            || ! $feed->loginCanAccessCustomer($login, (int) $model->company_id, (int) $model->customer_id)) {
            abort(Response::HTTP_NOT_FOUND);
        }

        // A refund is always individual; a referenced payment re-expands to its whole
        // έμβασμα group (same party + reference). Mirror the builder's skip of
        // zero/negative rows (#377) so the member list matches the statement exactly.
        $group = collect([$model]);
        if ($model->kind !== 'refund' && filled($model->reference)) {
            $group = Payment::query()
                ->withoutGlobalScope(CompanyScope::class)
                ->with(['paymentMethod', 'bankAccount', 'paymentIntent'])
                ->where('company_id', $model->company_id)
                ->where('customer_id', $model->customer_id)
                ->where('reference', $model->reference)
                ->where('kind', '!=', 'refund')
                ->where('amount', '>', 0)
                ->orderBy('id')
                ->get();
        }

        // Settled invoices, re-scoped to THIS (company, customer) so a stray invoice_id
        // can't resolve cross-customer, and re-gated to customer-visible so a link (and
        // even the bare invcode) is never shown for a doc this login can't open.
        $invoiceIds = $group->pluck('invoice_id')->filter()->unique()->values();
        $invoices = $invoiceIds->isEmpty()
            ? collect()
            : Invoice::query()
                ->withoutGlobalScope(CompanyScope::class)
                ->with('invoiceType')
                ->where('company_id', $model->company_id)
                ->where('customer_id', $model->customer_id)
                ->whereKey($invoiceIds)
                ->get()
                ->keyBy('id');

        $allocations = $group->map(function (Payment $p) use ($invoices): array {
            $inv = $p->invoice_id !== null ? $invoices->get($p->invoice_id) : null;
            $visible = $inv !== null && $inv->isCustomerVisible();

            return [
                'amount' => (float) $p->amount,
                'invoice_id' => $visible ? (int) $inv->id : null,
                'invcode' => $visible ? $inv->invcode : null,
                'type' => $visible ? $inv->invoiceType?->name : null,
                'on_account' => $p->invoice_id === null,
            ];
        })->all();

        $hasInvoiceAllocation = collect($allocations)->contains(fn (array $a): bool => ! $a['on_account']);

        // Title matches the statement's own vocabulary: refund → Επιστροφή; a lone
        // (reference-less) payment → Πληρωμή (the builder never groups it); a reference
        // group → Έμβασμα when it hit an invoice, else Είσπραξη.
        $titleKey = match (true) {
            $model->kind === 'refund' => 'refund',
            ! filled($model->reference) => 'payment',
            $hasInvoiceAllocation => 'remittance',
            default => 'collection',
        };

        return view('portal.receipt', [
            'payment' => $model,
            'kind' => $model->kind === 'refund' ? 'refund' : 'payment',
            'titleKey' => $titleKey,
            'total' => round((float) $group->sum(fn (Payment $p): float => (float) $p->amount), 2),
            'allocations' => $allocations,
            'provenance' => $this->sharedProvenance($group, $model, $gateways),
        ]);
    }

    /**
     * Provenance to display: for a single payment, its own values; for a group, only
     * the fields ALL members share (a differing field → null → omitted), so a summed
     * total is never attributed to one member's transaction id / method / notes.
     *
     * @param  Collection<int, Payment>  $group
     * @return array<string, ?string>
     */
    private function sharedProvenance(Collection $group, Payment $model, PaymentGatewayRegistry $gateways): array
    {
        $shared = function (callable $accessor) use ($group): ?string {
            $distinct = $group->map($accessor)->map(fn ($v): ?string => filled($v) ? (string) $v : null)->unique()->values();

            return $distinct->count() === 1 ? $distinct->first() : null;
        };

        // Channel as a translatable key + (brand) gateway name, from the SAME shared
        // Payment::channelParts() the operator label uses; 'mixed' when members came
        // through different channels.
        $tokens = $group->map(function (Payment $p): string {
            $c = $p->channelParts();

            return $c['key'].'|'.(string) ($c['gateway'] ?? '');
        })->unique();
        if ($tokens->count() === 1) {
            $parts = $group->first()->channelParts();
            $channelKey = $parts['key'];
            $gatewayName = $parts['gateway'] !== null ? $gateways->label($parts['gateway']) : null;
        } else {
            $channelKey = 'mixed';
            $gatewayName = null;
        }

        return [
            'channel_key' => $channelKey,
            'gateway_name' => $gatewayName,
            'method' => $shared(fn (Payment $p) => $p->paymentMethod?->description),
            'bank' => $shared(fn (Payment $p) => $p->bankAccount?->label()),
            'transaction_id' => $shared(fn (Payment $p) => $p->transaction_id),
            // The group key is uniform by construction.
            'reference' => filled($model->reference) ? (string) $model->reference : null,
            'notes' => $shared(fn (Payment $p) => $p->notes),
        ];
    }
}
