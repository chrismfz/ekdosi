<?php

namespace Tests\Feature\Portal;

use App\Models\Company;
use App\Models\Customer;
use App\Models\CustomerUser;
use App\Models\CustomerUserAccess;
use App\Models\Invoice;
use App\Models\InvoiceType;
use App\Models\Payment;
use App\Models\PaymentGatewayConnection;
use App\Models\PaymentIntent;
use App\Models\PaymentMethod;
use App\Services\Payments\PaymentIntentService;
use App\Services\Portal\CustomerDocumentFeed;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * «Προτιμολόγιο»: a draft the operator has OFFERED to the customer — visible and
 * settleable in the portal, while staying a draft everywhere else (no ΑΑ, no
 * myDATA, outside every money total).
 *
 * Why it exists: recurring-service renewals are staged as drafts. Issuing them
 * unilaterally and cancelling the ones the customer dropped would produce a
 * stream of ΑΚΥ/πιστωτικά — the pattern that draws AADE attention. Settling the
 * proforma first means those cancellations never need to exist.
 *
 * The load-bearing properties, all asserted here: an UN-offered draft stays
 * invisible; an offered one is visible AND payable; the legal-document channels
 * (isPubliclyViewable → signed public PDF, WHMCS proxy, e-mail) still refuse it;
 * and withdrawing the offer hides it again.
 */
class ProformaPayableTest extends TestCase
{
    use RefreshDatabase;

    private Company $tenant;

    private Customer $customer;

    private CustomerUser $login;

    private InvoiceType $type;

    private PaymentMethod $credit;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Company::create([
            'name' => 'T', 'slug' => 'pf-'.uniqid(), 'country_code' => 'GR',
            'einvoice_provider' => 'gr-mydata', 'mydata_mode' => 'off',
        ]);
        $this->customer = Customer::create([
            'company_id' => $this->tenant->id, 'name' => 'Πελάτης', 'afm' => '090000045',
        ]);
        $this->login = CustomerUser::factory()->create([
            'password' => Hash::make('secret-pass-123'),
            'status' => CustomerUser::STATUS_ACTIVE,
        ]);
        CustomerUserAccess::create([
            'customer_user_id' => $this->login->id,
            'company_id' => $this->tenant->id,
            'customer_id' => $this->customer->id,
            'role' => CustomerUserAccess::ROLE_OWNER,
            'granted_at' => now(),
        ]);
        $this->type = InvoiceType::create([
            'company_id' => $this->tenant->id, 'name' => 'ΤΠΥ', 'code' => 'ΤΠΥ', 'invcount' => 1,
        ]);
        $this->credit = PaymentMethod::create([
            'company_id' => $this->tenant->id, 'description' => 'Επί Πιστώσει', 'due_days' => 30,
        ]);
        // The pay page short-circuits to «no method available» without one, so the
        // picker (what this file actually asserts on) would never render.
        PaymentGatewayConnection::create([
            'company_id' => $this->tenant->id, 'gateway' => 'manual', 'label' => 'Κατάθεση',
            'is_active' => true, 'sort' => 0, 'config' => [],
        ]);
    }

    /** A draft worth €100, offered or not. */
    private function draft(bool $offered, float $gross = 100.0): Invoice
    {
        $inv = Invoice::create([
            'company_id' => $this->tenant->id,
            'invoice_type_id' => $this->type->id,
            'customer_id' => $this->customer->id,
            'invcode' => 'ΠΡΟΣ-ΤΠΥ-'.random_int(1000, 9999),
            'issued_at' => now(),
            'payment_method_id' => $this->credit->id,
        ]);
        $inv->forceFill([
            'local_status' => 'draft',
            'offered_at' => $offered ? now() : null,
            'net_total' => $gross,
            'gross_total' => $gross,
        ])->save();

        return $inv;
    }

    private function feed(): CustomerDocumentFeed
    {
        return app(CustomerDocumentFeed::class);
    }

    public function test_an_un_offered_draft_stays_invisible_to_the_customer(): void
    {
        $draft = $this->draft(offered: false);

        $this->assertFalse($draft->isOffered());
        $this->assertFalse($this->feed()->loginCanAccess($this->login, $draft));
        $this->assertSame([], $this->feed()->documentsFor($this->tenant->id, $this->customer->id));
    }

    public function test_an_offered_draft_is_visible_to_the_customer(): void
    {
        $proforma = $this->draft(offered: true);

        $this->assertTrue($proforma->isOffered());
        $this->assertTrue($this->feed()->loginCanAccess($this->login, $proforma));

        $docs = $this->feed()->documentsFor($this->tenant->id, $this->customer->id);
        $this->assertCount(1, $docs);
        $this->assertSame($proforma->id, $docs[0]['id']);
    }

    /**
     * THE containment property. Widening the portal must not widen the channels
     * that promise a «παραστατικό ΑΑΔΕ»: the signed public PDF route, the WHMCS
     * PDF proxy, the issued-for-client list and the invoice e-mail all gate on
     * isPubliclyViewable(), which must keep refusing a proforma.
     */
    public function test_a_proforma_is_still_refused_by_the_legal_document_channels(): void
    {
        $proforma = $this->draft(offered: true);

        $this->assertFalse($proforma->isPubliclyViewable());
        $this->assertTrue($proforma->isCustomerVisible());
        $this->assertTrue($proforma->isCustomerPayable());
    }

    public function test_withdrawing_the_offer_hides_it_again(): void
    {
        $proforma = $this->draft(offered: true);
        $this->assertTrue($this->feed()->loginCanAccess($this->login, $proforma));

        $proforma->forceFill(['offered_at' => null])->save();

        $this->assertFalse($this->feed()->loginCanAccess($this->login->fresh(), $proforma->fresh()));
    }

    /** A proforma is a payable target in the portal; an un-offered draft is not. */
    public function test_only_an_offered_draft_is_offered_as_a_payment_target(): void
    {
        $hidden = $this->draft(offered: false);
        $proforma = $this->draft(offered: true);

        $this->actingAs($this->login, 'portal')
            ->get(route('portal.payment.create', $this->customer->id))
            ->assertOk()
            ->assertSee($proforma->invcode)
            ->assertDontSee($hidden->invcode);
    }

    /**
     * The customer points their own existing credit at a proforma — no new money,
     * a re-point of what they already paid. This is the flow that lets a renewal
     * be settled before it is ever issued.
     */
    public function test_a_customer_can_settle_a_proforma_from_their_credit(): void
    {
        $proforma = $this->draft(offered: true, gross: 40.0);

        // €100 sitting on account (an earlier overpayment).
        Payment::create([
            'company_id' => $this->tenant->id,
            'customer_id' => $this->customer->id,
            'amount' => 100.00,
            'pay_date' => now()->subDay(),
            'kind' => 'payment',
        ]);

        $this->actingAs($this->login, 'portal')
            ->post(route('portal.payment.apply-credit', $this->customer->id), [
                'invoice_id' => $proforma->id,
                'amount' => 40.00,
            ])
            ->assertRedirect(route('portal.statement'));

        $this->assertSame(0.0, (float) $proforma->fresh()->balanceData()->balance);
        $this->assertSame(
            40.0,
            (float) Payment::where('invoice_id', $proforma->id)->sum('amount'),
        );
    }

    /** …and may NOT point it at a document that was never offered to them. */
    public function test_a_customer_cannot_settle_an_un_offered_draft_from_credit(): void
    {
        $hidden = $this->draft(offered: false, gross: 40.0);

        Payment::create([
            'company_id' => $this->tenant->id,
            'customer_id' => $this->customer->id,
            'amount' => 100.00,
            'pay_date' => now()->subDay(),
            'kind' => 'payment',
        ]);

        $this->actingAs($this->login, 'portal')
            ->post(route('portal.payment.apply-credit', $this->customer->id), [
                'invoice_id' => $hidden->id,
                'amount' => 40.00,
            ])
            ->assertNotFound();

        $this->assertSame(0, Payment::where('invoice_id', $hidden->id)->count());
    }

    /**
     * THE settle path — a real payment (not credit) captured against a proforma.
     *
     * Regression guard: `payableTarget()` was widened to accept a proforma while
     * `PaymentAllocator::allocateToInvoice()` still re-resolved the target as
     * `local_status = active` and THREW. Because settle() runs inside one
     * DB::transaction and the vPOS controller does not catch, the throw rolled
     * everything back: the bank had taken the money and we wrote no Payment, no
     * intent transition and no «Log πύλης» row. Both now share
     * InvoiceScope::customerSettleable().
     */
    public function test_a_payment_captured_against_a_proforma_lands_on_it(): void
    {
        $proforma = $this->draft(offered: true, gross: 60.0);
        $connection = PaymentGatewayConnection::where('company_id', $this->tenant->id)->firstOrFail();

        $result = app(PaymentIntentService::class)->start(
            customer: $this->customer,
            connection: $connection,
            amount: 60.0,
            login: $this->login,
            invoice: $proforma,
        );

        $outcome = app(PaymentIntentService::class)->settle(
            $result['intent'],
            settledBy: 'test',
            transactionId: 'TX-PROFORMA',
        );

        $this->assertSame(PaymentIntentService::SETTLE_OK, $outcome);
        $this->assertSame(PaymentIntent::STATUS_SETTLED, $result['intent']->fresh()->status);

        // The money is ON the proforma, not stranded on account.
        $this->assertSame(60.0, (float) Payment::where('invoice_id', $proforma->id)->sum('amount'));
        $this->assertSame(0.0, (float) $proforma->fresh()->balanceData()->balance);
        $this->assertSame(0, Payment::whereNull('invoice_id')
            ->where('customer_id', $this->customer->id)->count());
    }

    /** Issuing the settled proforma keeps the payment attached — it is the same row. */
    public function test_issuing_a_settled_proforma_keeps_its_payment(): void
    {
        $proforma = $this->draft(offered: true, gross: 60.0);
        Payment::create([
            'company_id' => $this->tenant->id, 'customer_id' => $this->customer->id,
            'invoice_id' => $proforma->id, 'amount' => 60.00,
            'pay_date' => now(), 'kind' => 'payment',
        ]);

        // «Οριστικοποίηση»: same row, now a legal document.
        $proforma->forceFill(['local_status' => 'active', 'offered_at' => null])->save();

        $this->assertSame(60.0, (float) Payment::where('invoice_id', $proforma->id)->sum('amount'));
        $this->assertSame(0.0, (float) $proforma->fresh()->balanceData()->balance);
    }

    /**
     * Applying existing credit is a re-point, not a charge — it needs no gateway.
     * The block used to sit inside the «no payment method available» else-branch,
     * so a tenant without an active gateway left the customer looking at credit
     * they could not touch.
     */
    public function test_credit_can_be_applied_even_with_no_active_gateway(): void
    {
        PaymentGatewayConnection::where('company_id', $this->tenant->id)->delete();
        $proforma = $this->draft(offered: true, gross: 40.0);
        Payment::create([
            'company_id' => $this->tenant->id, 'customer_id' => $this->customer->id,
            'amount' => 100.00, 'pay_date' => now()->subDay(), 'kind' => 'payment',
        ]);

        $this->actingAs($this->login, 'portal')
            ->get(route('portal.payment.create', $this->customer->id))
            ->assertOk()
            ->assertSee(__('portal.payment.use_credit_submit'));

        $this->actingAs($this->login, 'portal')
            ->post(route('portal.payment.apply-credit', $this->customer->id), [
                'invoice_id' => $proforma->id, 'amount' => 40.00,
            ])->assertRedirect(route('portal.statement'));

        $this->assertSame(40.0, (float) Payment::where('invoice_id', $proforma->id)->sum('amount'));
    }

    /**
     * A draft that holds money must not be silently reassigned or deleted — both
     * were impossible before the προτιμολόγιο, so the draft lifecycle never had to
     * defend against it. The guards key on this predicate.
     */
    public function test_a_proforma_holding_money_reports_it(): void
    {
        $proforma = $this->draft(offered: true, gross: 40.0);
        $this->assertFalse($proforma->hasRecordedPayments());

        Payment::create([
            'company_id' => $this->tenant->id, 'customer_id' => $this->customer->id,
            'invoice_id' => $proforma->id, 'amount' => 40.00,
            'pay_date' => now(), 'kind' => 'payment',
        ]);

        $this->assertTrue($proforma->fresh()->hasRecordedPayments());
    }
}
