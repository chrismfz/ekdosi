<?php

namespace Tests\Feature\WhmcsInbox;

use App\Enums\PaymentStatus;
use App\Models\Company;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\InvoiceType;
use App\Models\PaymentMethod;
use App\Models\PendingWhmcsInvoice;
use App\Models\VatCategory;
use App\Services\InvoiceBalance;
use App\Services\WhmcsInbox\WhmcsInvoiceFiler;
use App\Services\WhmcsInbox\WhmcsInvoiceMapper;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * An UNPAID invoice issued before payment (forced from WHMCS, «Send to Ekdosi» from MyCS) stays an open receivable:
 * when the type's default method is cash-term (due_days 0, settled at issue with no payment) it takes the tenant's
 * «Επί Πιστώσει» (myDATA type 5, with due days). Found live on 2026-10-27: myip had no unpaid default type, so an
 * unpaid MyCS invoice was issued as ΤΠΥ with «Ηλεκτρονικά μέσα» and read paid with no payment.
 */
class WhmcsUnpaidCreditTermTest extends TestCase
{
    use RefreshDatabase;

    private Company $tenant;

    private PaymentMethod $cash;

    private PaymentMethod $credit;

    private InvoiceType $invoiceType;

    private Customer $customer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = Company::create([
            'name' => 'U', 'slug' => 'u-'.uniqid(),
            'country_code' => 'GR', 'einvoice_provider' => 'gr-mydata', 'mydata_mode' => 'off',
        ]);
        VatCategory::create(['company_id' => $this->tenant->id, 'name' => '24%', 'rate' => 24.00, 'is_default' => true]);
        $this->cash = PaymentMethod::create([
            'company_id' => $this->tenant->id, 'description' => 'Ηλεκτρονικά μέσα', 'due_days' => 0, 'mydata_payment_type' => 7,
        ]);
        $this->credit = PaymentMethod::create([
            'company_id' => $this->tenant->id, 'description' => 'Επί Πιστώσει', 'due_days' => 15, 'mydata_payment_type' => 5,
        ]);
        $this->invoiceType = InvoiceType::create([
            'company_id' => $this->tenant->id, 'name' => 'ΤΠΥ', 'code' => 'ΤΠΥ', 'invcount' => 1, 'payment_method_id' => $this->cash->id,
        ]);
        $this->customer = Customer::create(['company_id' => $this->tenant->id, 'name' => 'ΑΚΜΕ', 'afm' => '111111111']);
    }

    private function row(string $status, int $id = 7001): PendingWhmcsInvoice
    {
        return PendingWhmcsInvoice::create([
            'company_id' => $this->tenant->id,
            'whmcs_invoice_id' => $id,
            'payload' => [
                'invoiceid' => $id, 'status' => $status, 'total' => '24.80', 'subtotal' => '20.00', 'tax' => '4.80', 'taxrate' => '24.00',
                'items' => ['item' => [['id' => 1, 'description' => 'Hosting', 'amount' => '20.00', 'taxed' => '1']]],
            ],
            'match_reason' => PendingWhmcsInvoice::REASON_LINKED,
            'status' => PendingWhmcsInvoice::STATUS_PENDING_REVIEW,
        ]);
    }

    private function methodFor(PendingWhmcsInvoice $row, ?Company $tenant = null): ?int
    {
        return app(WhmcsInvoiceMapper::class)
            ->map($tenant ?? $this->tenant->fresh(), $row, $this->customer, $this->invoiceType)['header']['payment_method_id'];
    }

    public function test_an_unpaid_invoice_takes_the_credit_term_method_over_a_cash_term_default(): void
    {
        $this->assertSame($this->credit->id, $this->methodFor($this->row('Unpaid')));
    }

    public function test_a_paid_invoice_keeps_the_types_default(): void
    {
        $this->assertSame($this->cash->id, $this->methodFor($this->row('Paid')));
    }

    public function test_a_credit_term_default_is_kept_as_it_is(): void
    {
        $thirty = PaymentMethod::create(['company_id' => $this->tenant->id, 'description' => 'Πίστωση 30', 'due_days' => 30]);
        $this->invoiceType->update(['payment_method_id' => $thirty->id]);

        $this->assertSame($thirty->id, $this->methodFor($this->row('Unpaid')));
    }

    public function test_a_type_5_method_without_due_days_is_not_credit_term(): void
    {
        // As NEXON's «Επί Πιστώσει» on production: type 5, 0 days. Issued with it, it would still be settled at issue.
        $this->credit->update(['due_days' => 0]);

        $this->assertSame($this->cash->id, $this->methodFor($this->row('Unpaid')), 'no credit-term method: the default stands');
    }

    public function test_a_deleted_or_another_tenants_credit_method_is_never_used(): void
    {
        $this->credit->delete();
        $other = Company::create(['name' => 'O', 'slug' => 'o-'.uniqid(), 'country_code' => 'GR', 'einvoice_provider' => 'gr-mydata', 'mydata_mode' => 'off']);
        PaymentMethod::create(['company_id' => $other->id, 'description' => 'Επί Πιστώσει', 'due_days' => 15, 'mydata_payment_type' => 5]);

        $this->assertSame($this->cash->id, $this->methodFor($this->row('Unpaid')));
    }

    public function test_an_issued_unpaid_invoice_stays_an_open_receivable(): void
    {
        $invoice = app(WhmcsInvoiceFiler::class)->file($this->tenant, $this->row('Unpaid'), $this->customer, $this->invoiceType)->invoice;
        // Off mode keeps a filed invoice a draft, which InvoiceBalance never settles: mark it issued, as a live filing does
        // (adversarial ADV-5 of #704: without it the balance proves nothing).
        Invoice::query()->whereKey($invoice->id)->update(['local_status' => 'active']);

        $this->assertSame($this->credit->id, $invoice->payment_method_id);
        $balance = app(InvoiceBalance::class)->for($invoice->fresh());
        $this->assertSame(PaymentStatus::Unpaid, $balance->status);
        $this->assertEqualsWithDelta(24.80, $balance->balance, 0.001, 'owed until a payment closes it');
    }

    public function test_the_draft_takes_it_too(): void
    {
        $invoice = app(WhmcsInvoiceFiler::class)->createDraft($this->tenant, $this->row('Unpaid'), $this->customer, $this->invoiceType);

        $this->assertSame($this->credit->id, $invoice->payment_method_id);
    }

    public function test_the_preview_says_how_it_is_paid_and_warns_without_a_credit_method(): void
    {
        $preview = fn () => view('filament.whmcs-inbox.preview-partial', ['preview' => app(WhmcsInvoiceFiler::class)
            ->preview($this->tenant->fresh(), $this->row('Unpaid', random_int(8000, 9999)), $this->customer, $this->invoiceType)])->render();

        $this->assertStringContainsString('Επί Πιστώσει', $preview());
        $this->assertStringNotContainsString('θα εκδοθεί εξοφλημένο', $preview());

        $this->credit->delete();
        $this->assertStringContainsString('θα εκδοθεί εξοφλημένο', $preview());
    }

    public function test_payment_pending_and_collections_are_not_paid_either(): void
    {
        $this->assertSame($this->credit->id, $this->methodFor($this->row('Payment Pending')));
        $this->assertSame($this->credit->id, $this->methodFor($this->row('Collections', 7002)));
        $this->assertSame($this->cash->id, $this->methodFor($this->row('Cancelled', 7003)), 'not issued: as it was');
    }

    public function test_the_preview_warns_about_what_was_paid_before_issue(): void
    {
        $render = function (array $extra, int $id): string {
            $row = $this->row('Unpaid', $id);
            $row->update(['payload' => $extra + $row->payload]);

            return view('filament.whmcs-inbox.preview-partial', ['preview' => app(WhmcsInvoiceFiler::class)
                ->preview($this->tenant->fresh(), $row->fresh(), $this->customer, $this->invoiceType)])->render();
        };

        // WHMCS: account credit plus an incoming transaction (one, as a bare map) less a refund.
        $whmcs = $render(['credit' => '4.00', 'transactions' => ['transaction' => ['transid' => 'T1', 'amountin' => '7.00', 'amountout' => '1.00']]], 8101);
        $this->assertStringContainsString('Έχει ήδη πληρωθεί 10,00 €', $whmcs);
        // MyCS: a list with amount/refunded.
        $mycs = $render(['credit' => '0.00', 'transactions' => [['transid' => 'M1', 'amount' => '5.00', 'refunded' => '0.00']]], 8102);
        $this->assertStringContainsString('Έχει ήδη πληρωθεί 5,00 €', $mycs);
        $this->assertStringNotContainsString('Έχει ήδη πληρωθεί', $render([], 8103));
    }
}
