<?php

namespace App\Http\Controllers\Portal;

use App\Contracts\HostedRedirectGateway;
use App\Http\Controllers\Controller;
use App\Models\Invoice;
use App\Models\PaymentGatewayConnection;
use App\Models\PaymentIntent;
use App\Models\Scopes\CompanyScope;
use App\Services\CustomerLedger\CustomerLedgerBuilder;
use App\Services\Payments\PaymentAllocator;
use App\Services\Payments\PaymentGatewayRegistry;
use App\Services\Payments\PaymentIntentService;
use App\Services\Portal\CustomerDocumentFeed;
use App\Support\InvoiceScope;
use App\Support\Money;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\Response;

/**
 * Portal «Πλήρωσε» (Πυλώνας B / B0b). The customer starts a payment against a
 * granted (company, customer): pick an amount + an active method → a pending
 * PaymentIntent + where-to-pay. Grant-scoped throughout (same boundary as
 * documents/statement); the browser NEVER settles money.
 */
class PaymentController extends Controller
{
    public function __construct(
        private CustomerDocumentFeed $boundary,
        private PaymentGatewayRegistry $registry,
        private CustomerLedgerBuilder $ledger,
        private PaymentIntentService $intents,
    ) {}

    public function create(Request $request, int $customer): View
    {
        $model = $this->resolveCustomer($customer);
        $methods = $this->activeMethods((int) $model->company_id);
        $owed = max((float) $this->ledger->build($model)->stats['balance'], 0.0);
        $openInvoices = $this->payableInvoices($model);

        // Optional deep-link «pay THIS invoice» (?invoice=…) — honoured only if it
        // is one of the customer's own payable documents.
        $preselect = (int) $request->query('invoice', 0);
        $preselected = $preselect > 0 ? $openInvoices->firstWhere('id', $preselect) : null;

        return view('portal.payment.create', [
            'customer' => $model,
            'methods' => $methods,
            'owed' => $owed,
            'openInvoices' => $openInvoices,
            'preselectedInvoiceId' => $preselected?->id,
            // Money the customer already has with us (overpayment, unapplied credit
            // note, on-account gateway payment). Surfaced so it stops being a number
            // they can see but not use.
            'availableCredit' => app(PaymentAllocator::class)->availableCredit($model),
        ]);
    }

    public function store(Request $request, int $customer): RedirectResponse
    {
        $model = $this->resolveCustomer($customer);

        $data = $request->validate([
            'connection_id' => ['required', 'integer'],
            'amount' => ['required', 'numeric', 'min:0.01', 'max:9999999'],
            'invoice_id' => ['nullable', 'integer'],
        ]);

        $connection = $this->activeMethods((int) $model->company_id)->firstWhere('id', (int) $data['connection_id']);
        if ($connection === null) {
            abort(Response::HTTP_NOT_FOUND);
        }

        // Invoice target is OPTIONAL — «Όλο το υπόλοιπο» leaves it null (FIFO). When
        // set it must be one of THIS customer's payable invoices (never trust the id).
        $invoice = null;
        if (filled($data['invoice_id'] ?? null)) {
            $invoice = $this->payableInvoices($model)->firstWhere('id', (int) $data['invoice_id']);
            if ($invoice === null) {
                abort(Response::HTTP_NOT_FOUND);
            }
        }

        $result = $this->intents->start(
            customer: $model,
            connection: $connection,
            amount: (float) $data['amount'],
            login: Auth::guard('portal')->user(),
            invoice: $invoice,
        );

        // A hosted gateway (flow=redirect) bounces the customer to its own page
        // (built + auto-submitted on our redirect route); the offline gateway just
        // shows the bank details. The browser never settles money either way.
        $initiation = $result['initiation'];
        if ($initiation->flow !== 'offline' && filled($initiation->redirectUrl)) {
            return redirect()->to($initiation->redirectUrl);
        }

        return redirect()->route('portal.payment.show', $result['intent']->id);
    }

    /**
     * «Χρήση πίστωσης»: move on-account credit onto one of the customer's own
     * documents — an issued invoice or an offered προτιμολόγιο.
     *
     * This creates NO money: PaymentAllocator::applyCredit re-points payments the
     * customer has already made, so their total balance is unchanged and the
     * operator's confirmation adds nothing. The guards that matter are ownership
     * (grant-scoped customer, and a target drawn from their own payable set) and
     * the allocator's own caps (never more than the credit, never more than the
     * document's balance).
     */
    public function applyCredit(Request $request, int $customer): RedirectResponse
    {
        $model = $this->resolveCustomer($customer);

        $data = $request->validate([
            'invoice_id' => ['required', 'integer'],
            'amount' => ['required', 'numeric', 'min:0.01', 'max:9999999'],
        ]);

        // Never trust the id: it must be one of THIS customer's payable documents.
        $invoice = $this->payableInvoices($model)->firstWhere('id', (int) $data['invoice_id']);
        if ($invoice === null) {
            abort(Response::HTTP_NOT_FOUND);
        }

        try {
            $applied = app(PaymentAllocator::class)->applyCredit($model, $invoice, (float) $data['amount']);
        } catch (\InvalidArgumentException $e) {
            return back()->withErrors(['amount' => $e->getMessage()]);
        }

        return redirect()->route('portal.statement')
            ->with('status', __('portal.payment.credit_applied', [
                'amount' => Money::eur($applied),
                'document' => (string) $invoice->invcode,
            ]));
    }

    /**
     * The bounce page for a hosted (flow=redirect) gateway: rebuild the SIGNED
     * provider form and auto-submit the customer's browser to the acquirer. Rebuilt
     * here (not stashed) so the signed payload is never persisted and survives a
     * refresh. Grant-scoped; a non-pending intent falls back to its status page.
     */
    public function redirect(Request $request, int $intent): View|RedirectResponse
    {
        $model = $this->resolveOwnIntent($intent);

        if (! $model->isPending()) {
            return redirect()->route('portal.payment.show', $model->id);
        }

        $connection = $this->connectionFor($model);
        $gateway = $this->registry->for($model->gateway);
        if ($connection === null || ! $gateway instanceof HostedRedirectGateway) {
            // Not a redirectable intent (offline, or the method was removed) — send
            // the customer to the status/instructions page instead of erroring.
            return redirect()->route('portal.payment.show', $model->id);
        }

        return view('portal.payment.redirect', [
            'intent' => $model,
            'form' => $gateway->redirectForm($model, $connection),
        ]);
    }

    public function show(Request $request, int $intent): View
    {
        return view('portal.payment.show', ['intent' => $this->resolveOwnIntent($intent)]);
    }

    /** One of the login's OWN intents by id (grant-scoped), or a flat 404. */
    private function resolveOwnIntent(int $intent): PaymentIntent
    {
        $model = PaymentIntent::query()
            ->withoutGlobalScope(CompanyScope::class)
            ->whereKey($intent)
            ->first();

        if ($model === null || ! $this->loginSees($model)) {
            abort(Response::HTTP_NOT_FOUND);
        }

        return $model;
    }

    /**
     * The intent's own ACTIVE connection (same company), or null. is_active is
     * re-checked here (not just at start()): if the operator disabled the method
     * after the intent was created, we must NOT auto-submit a live payment form
     * through it — the customer falls back to the status page.
     */
    private function connectionFor(PaymentIntent $intent): ?PaymentGatewayConnection
    {
        if ($intent->payment_gateway_connection_id === null) {
            return null;
        }

        return PaymentGatewayConnection::query()
            ->withoutGlobalScope(CompanyScope::class)
            ->where('company_id', $intent->company_id)
            ->where('is_active', true)
            ->whereKey($intent->payment_gateway_connection_id)
            ->first();
    }

    /**
     * The granted customer BY ID (a login may hold grants to several customers in
     * one company, so the pay flow is keyed by customer, never by company), or a
     * flat 404 when no active grant matches.
     */
    private function resolveCustomer(int $customerId)
    {
        foreach ($this->boundary->grantedTargets(Auth::guard('portal')->user()) as $grant) {
            if ((int) $grant->customer_id === $customerId) {
                return $grant->customer;
            }
        }
        abort(Response::HTTP_NOT_FOUND);
    }

    /** Does this login hold an active grant matching the intent's (company, customer)? */
    private function loginSees(PaymentIntent $intent): bool
    {
        foreach ($this->boundary->grantedTargets(Auth::guard('portal')->user()) as $grant) {
            if ((int) $grant->company_id === (int) $intent->company_id
                && (int) $grant->customer_id === (int) $intent->customer_id) {
                return true;
            }
        }

        return false;
    }

    /**
     * The customer's PAYABLE invoices (live, issued/active, non-credit, with an
     * open balance), oldest-first — the choices for «πλήρωσε ΑΥΤΟ το τιμολόγιο».
     * Each carries an `open_balance` attribute for display/pre-fill. Grant-scoped
     * (the customer is already resolved from an active grant).
     *
     * @return \Illuminate\Support\Collection<int, Invoice>
     */
    private function payableInvoices(object $customer): \Illuminate\Support\Collection
    {
        $q = InvoiceScope::live(Invoice::query())
            ->withoutGlobalScope(CompanyScope::class)
            ->where('company_id', $customer->company_id)
            ->where('customer_id', $customer->id)
            ->orderBy('issued_at')
            ->orderBy('id');
        // Issued invoice OR offered προτιμολόγιο — the one shared definition.
        InvoiceScope::customerSettleable($q);
        InvoiceScope::excludeCreditNotes($q);

        return $q->get()
            ->each(fn (Invoice $inv) => $inv->setAttribute('open_balance', round((float) $inv->balanceData()->balance, 2)))
            ->filter(fn (Invoice $inv): bool => (float) $inv->open_balance > 0.005)
            ->values();
    }

    /**
     * A company's ACTIVE, chargeable payment methods, in display order.
     *
     * @return Collection<int, PaymentGatewayConnection>
     */
    private function activeMethods(int $company): Collection
    {
        return PaymentGatewayConnection::query()
            ->withoutGlobalScope(CompanyScope::class)
            ->where('company_id', $company)
            ->where('is_active', true)
            ->orderBy('sort')
            ->orderBy('id')
            ->get()
            ->filter(fn (PaymentGatewayConnection $c): bool => $this->registry->for($c->gateway)->capabilities()->chargeable())
            ->values();
    }
}
