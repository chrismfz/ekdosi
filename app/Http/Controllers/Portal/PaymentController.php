<?php

namespace App\Http\Controllers\Portal;

use App\Http\Controllers\Controller;
use App\Models\PaymentGatewayConnection;
use App\Models\PaymentIntent;
use App\Models\Scopes\CompanyScope;
use App\Services\CustomerLedger\CustomerLedgerBuilder;
use App\Services\Payments\PaymentGatewayRegistry;
use App\Services\Payments\PaymentIntentService;
use App\Services\Portal\CustomerDocumentFeed;
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

        return view('portal.payment.create', [
            'customer' => $model,
            'methods' => $methods,
            'owed' => $owed,
        ]);
    }

    public function store(Request $request, int $customer): RedirectResponse
    {
        $model = $this->resolveCustomer($customer);

        $data = $request->validate([
            'connection_id' => ['required', 'integer'],
            'amount' => ['required', 'numeric', 'min:0.01', 'max:9999999'],
        ]);

        $connection = $this->activeMethods((int) $model->company_id)->firstWhere('id', (int) $data['connection_id']);
        if ($connection === null) {
            abort(Response::HTTP_NOT_FOUND);
        }

        $result = $this->intents->start(
            customer: $model,
            connection: $connection,
            amount: (float) $data['amount'],
            login: Auth::guard('portal')->user(),
        );

        return redirect()->route('portal.payment.show', $result['intent']->id);
    }

    public function show(Request $request, int $intent): View
    {
        $model = PaymentIntent::query()
            ->withoutGlobalScope(CompanyScope::class)
            ->whereKey($intent)
            ->first();

        if ($model === null || ! $this->loginSees($model)) {
            abort(Response::HTTP_NOT_FOUND);
        }

        return view('portal.payment.show', ['intent' => $model]);
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
