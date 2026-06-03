<?php

namespace Tests\Feature\Services;

use App\Actions\StageServiceRenewal;
use App\Enums\BillingCycle;
use App\Enums\PaymentStatus;
use App\Enums\ServiceContractStatus;
use App\Models\Company;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\InvoiceLine;
use App\Models\InvoiceType;
use App\Models\PaymentMethod;
use App\Models\ServiceContract;
use App\Services\InvoiceBalance;
use App\Support\InvoiceScope;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use InvalidArgumentException;
use Tests\TestCase;

/**
 * StageServiceRenewal: stages a DRAFT invoice for a due contract, advances the
 * billing cursor, idempotent within a period, and refuses without an invoice
 * type. Mirrors IssueCreditNote's transaction shape (no AADE, no money cache).
 */
class StageServiceRenewalTest extends TestCase
{
    use RefreshDatabase;

    private Company $tenant;

    private Customer $customer;

    private InvoiceType $type;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Company::create(['name' => 'Rec', 'slug' => 'rec-'.uniqid(), 'country_code' => 'GR']);
        $this->customer = Customer::create([
            'company_id' => $this->tenant->id, 'name' => 'Πελάτης ΑΕ', 'afm' => '123456789',
            'address1' => 'Οδός 1', 'city' => 'Αθήνα', 'postcode' => '11111', 'country' => 'GR',
        ]);
        $this->type = InvoiceType::create([
            'company_id' => $this->tenant->id, 'code' => 'ΤΠΥ', 'name' => 'Τιμολόγιο Παροχής',
            'invcount' => 1, 'mydata_type' => '2.1',
        ]);
        PaymentMethod::create(['company_id' => $this->tenant->id, 'description' => 'Μετρητά', 'due_days' => 0]);
    }

    private function makeContract(array $overrides = []): ServiceContract
    {
        return ServiceContract::create(array_merge([
            'company_id' => $this->tenant->id,
            'customer_id' => $this->customer->id,
            'invoice_type_id' => $this->type->id,
            'description' => 'Hosting Personal5',
            'billing_cycle' => BillingCycle::Annual->value,
            'amount' => 100,
            'vat_percent' => 24,
            'status' => ServiceContractStatus::Active->value,
            'start_date' => Carbon::today()->subYear(),
            'next_due_date' => Carbon::yesterday(),
        ], $overrides));
    }

    public function test_stages_a_draft_invoice_and_advances_cursor(): void
    {
        $contract = $this->makeContract();
        $dueBefore = Carbon::parse($contract->next_due_date);

        $invoice = app(StageServiceRenewal::class)($contract);

        $this->assertInstanceOf(Invoice::class, $invoice);
        $this->assertSame('draft', $invoice->local_status);
        $this->assertNull($invoice->mydata_mark);
        $this->assertNull($invoice->mydata_state);
        $this->assertSame($contract->id, $invoice->service_contract_id);
        $this->assertSame('ΤΠΥ1', $invoice->invcode);

        // One line from the snapshot; net=100, gross=124.
        $this->assertSame(1, InvoiceLine::where('invoice_id', $invoice->id)->count());
        $this->assertEqualsWithDelta(100.0, (float) $invoice->net_total, 0.01);
        $this->assertEqualsWithDelta(124.0, (float) $invoice->gross_total, 0.01);

        // Party snapshot copied from the customer.
        $this->assertSame('Πελάτης ΑΕ', $invoice->company_name);
        $this->assertSame('123456789', $invoice->vat_no);

        // Cursor advanced by the cycle (+1 year), last_invoiced_at stamped.
        $contract->refresh();
        $this->assertSame($dueBefore->copy()->addYear()->toDateString(), Carbon::parse($contract->next_due_date)->toDateString());
        $this->assertNotNull($contract->last_invoiced_at);
    }

    public function test_is_idempotent_within_the_same_period(): void
    {
        $contract = $this->makeContract();

        $first = app(StageServiceRenewal::class)($contract);
        $this->assertNotNull($first);

        // Second call: cursor already advanced past today → nothing due.
        $second = app(StageServiceRenewal::class)($contract->fresh());
        $this->assertNull($second);
        $this->assertSame(1, Invoice::where('service_contract_id', $contract->id)->count());
    }

    public function test_skips_when_an_unissued_draft_already_exists(): void
    {
        // A contract whose cursor is due but already has an open draft for it.
        $contract = $this->makeContract();
        $first = app(StageServiceRenewal::class)($contract);
        $this->assertNotNull($first);

        // Force the cursor back to due WITHOUT issuing the existing draft.
        $contract->forceFill(['next_due_date' => Carbon::yesterday()->toDateString()])->save();

        $second = app(StageServiceRenewal::class)($contract->fresh());
        $this->assertNull($second, 'Should not open a second draft while one is unissued.');
        $this->assertSame(1, Invoice::where('service_contract_id', $contract->id)->count());
    }

    public function test_throws_when_invoice_type_is_null(): void
    {
        $contract = $this->makeContract(['invoice_type_id' => null]);

        $this->expectException(InvalidArgumentException::class);
        app(StageServiceRenewal::class)($contract);
    }

    public function test_draft_is_not_a_receivable_beyond_a_normal_draft(): void
    {
        // The staged draft is a normal draft invoice. InvoiceScope::live counts
        // non-cancelled/non-AADE-cancelled invoices regardless of draft/active,
        // so it appears there — but it carries no special money treatment.
        $contract = $this->makeContract();
        $invoice = app(StageServiceRenewal::class)($contract);

        // It IS a live (not cancelled) invoice — same as any other draft.
        $this->assertTrue(
            InvoiceScope::live(Invoice::query()->whereKey($invoice->id))->exists()
        );
        // The action never wrote a credit cache directly — it goes through the
        // normal InvoiceBalance recompute path like any other invoice. (paid_total
        // for a cash-term invoice with no payment method is the normal "settled at
        // issue" treatment, NOT a special money write by this action.)
        $this->assertEqualsWithDelta(0.0, (float) $invoice->credited_total, 0.01);
    }

    public function test_returns_null_when_not_due(): void
    {
        $contract = $this->makeContract(['next_due_date' => Carbon::tomorrow()]);
        $this->assertNull(app(StageServiceRenewal::class)($contract));
    }

    public function test_credit_term_contract_stays_a_receivable_not_auto_settled(): void
    {
        // A credit-term method (due_days>0) on the contract → the staged draft
        // inherits it → it's a REAL receivable that can go overdue (dunning),
        // NOT cash-term «settled at issue». This is why the contract carries a
        // payment method.
        $credit = PaymentMethod::create(['company_id' => $this->tenant->id, 'description' => 'Κατάθεση', 'due_days' => 30]);
        $contract = $this->makeContract(['payment_method_id' => $credit->id]);

        $invoice = app(StageServiceRenewal::class)($contract)->fresh();

        $this->assertSame($credit->id, $invoice->payment_method_id);
        $bal = app(InvoiceBalance::class)->for($invoice);
        $this->assertGreaterThan(0.0, (float) $bal->balance, 'credit-term draft owes money (not auto-settled)');
        $this->assertSame(PaymentStatus::Unpaid, $bal->status);
    }

    public function test_payment_method_falls_back_to_invoice_type_default(): void
    {
        $credit = PaymentMethod::create(['company_id' => $this->tenant->id, 'description' => 'Πίστωση', 'due_days' => 30]);
        $this->type->update(['payment_method_id' => $credit->id]);
        $contract = $this->makeContract(['payment_method_id' => null]); // none on contract

        $invoice = app(StageServiceRenewal::class)($contract)->fresh();

        $this->assertSame($credit->id, $invoice->payment_method_id, 'falls back to the renewal type default');
    }
}
