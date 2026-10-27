<?php

namespace Tests\Feature\Adversarial;

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
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Adversarial review of PR #704 (an unpaid WHMCS/MyCS invoice is issued «Επί Πιστώσει», never settled at issue).
 * Setup mirrors tests/Feature/WhmcsInbox/WhmcsUnpaidCreditTermTest.php.
 */
class Adv704Test extends TestCase
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

    /** @param  array<string, mixed>  $extra */
    private function row(string $status, array $extra = [], int $id = 7001): PendingWhmcsInvoice
    {
        return PendingWhmcsInvoice::create([
            'company_id' => $this->tenant->id,
            'whmcs_invoice_id' => $id,
            'payload' => $extra + [
                'invoiceid' => $id, 'status' => $status, 'total' => '24.80', 'subtotal' => '20.00', 'tax' => '4.80', 'taxrate' => '24.00',
                'items' => ['item' => [['id' => 1, 'description' => 'Hosting', 'amount' => '20.00', 'taxed' => '1']]],
            ],
            'match_reason' => PendingWhmcsInvoice::REASON_LINKED,
            'status' => PendingWhmcsInvoice::STATUS_PENDING_REVIEW,
        ]);
    }

    /**
     * mydata_mode off keeps a filed invoice local_status=draft, which InvoiceBalance never settles at issue
     * (isUnissuedSaleDraft), so it would hide the very defect under test. Mark it issued, as a live filing does.
     */
    private function issued(Invoice $invoice): Invoice
    {
        Invoice::query()->whereKey($invoice->id)->update(['local_status' => 'active']);

        return $invoice->fresh();
    }

    private function methodFor(PendingWhmcsInvoice $row): ?int
    {
        return app(WhmcsInvoiceMapper::class)
            ->map($this->tenant->fresh(), $row, $this->customer, $this->invoiceType)['header']['payment_method_id'];
    }

    /**
     * ADV-1 (P1). The type's default is a credit-term method that has since been SOFT-DELETED. isCreditTerm() reads it
     * withoutGlobalScopes (trashed included) and says «credit-term», so the deleted id is kept. But Invoice::paymentMethod
     * (belongsTo, SoftDeletes scope) resolves it to null → InvoiceBalance::isCashTerm() → settled at issue with no
     * payment (and AADE files paymentMethods type 3, cash). A live «Επί Πιστώσει» exists and should have been used.
     */
    public function test_adv1_a_soft_deleted_credit_term_default_is_not_kept_for_an_unpaid_invoice(): void
    {
        $old = PaymentMethod::create(['company_id' => $this->tenant->id, 'description' => 'Πίστωση 30 (παλιό)', 'due_days' => 30, 'mydata_payment_type' => 5]);
        $this->invoiceType->update(['payment_method_id' => $old->id]);
        $old->delete();

        $invoice = app(WhmcsInvoiceFiler::class)->file($this->tenant, $this->row('Unpaid'), $this->customer, $this->invoiceType)->invoice;
        $invoice = $this->issued($invoice);

        $balance = app(InvoiceBalance::class)->for($invoice->fresh());
        $this->assertSame(PaymentStatus::Unpaid, $balance->status, 'unpaid at source, issued with a deleted method: read as settled at issue');
        $this->assertSame($this->credit->id, $invoice->payment_method_id);
    }

    /**
     * ADV-1b (P1, same root cause). The preview resolves the method withoutGlobalScopes too, so it shows the DELETED
     * method as «επί πιστώσει: ανοιχτή οφειλή» with no warning, while the filed document is settled at issue
     * (and AADE gets paymentMethods type 3): preview and filing disagree.
     */
    public function test_adv1b_the_preview_does_not_present_a_deleted_method_as_credit_term(): void
    {
        $old = PaymentMethod::create(['company_id' => $this->tenant->id, 'description' => 'Πίστωση 30 (παλιό)', 'due_days' => 30, 'mydata_payment_type' => 5]);
        $this->invoiceType->update(['payment_method_id' => $old->id]);
        $old->delete();

        $html = view('filament.whmcs-inbox.preview-partial', ['preview' => app(WhmcsInvoiceFiler::class)
            ->preview($this->tenant->fresh(), $this->row('Unpaid', [], 7201), $this->customer, $this->invoiceType)])->render();

        $this->assertStringNotContainsString('Πίστωση 30 (παλιό)', $html, 'a deleted method is shown as the credit-term one');
    }

    /**
     * DEMO (passes; P3 test-quality). With mydata_mode off a filed invoice stays local_status=draft, and InvoiceBalance
     * never settles an unissued draft — so even a CASH-term filing reads Unpaid/24.80. The PR's
     * test_an_issued_unpaid_invoice_stays_an_open_receivable balance assertions are therefore vacuous; only its
     * payment_method_id assertion guards the fix. Its setup should mark the invoice issued (as issued() here does).
     */
    public function test_demo_off_mode_filing_cannot_observe_settlement_at_issue(): void
    {
        // No «Επί Πιστώσει»: the unpaid row keeps the cash-term default (the PR's no-credit-method fallback).
        $this->credit->delete();
        $invoice = app(WhmcsInvoiceFiler::class)->file($this->tenant, $this->row('Unpaid', [], 7301), $this->customer, $this->invoiceType)->invoice;

        $this->assertSame($this->cash->id, $invoice->payment_method_id);
        $this->assertSame(PaymentStatus::Unpaid, app(InvoiceBalance::class)->for($invoice->fresh())->status, 'cash-term, yet not settled: draft');
        $this->assertSame(PaymentStatus::Paid, app(InvoiceBalance::class)->for($this->issued($invoice))->status, 'issued: settled');
    }

    /**
     * ADV-2 (P2). The fix keys on exact 'Unpaid' only. WHMCS 'Collections' and 'Payment Pending' are not-yet-paid too
     * (the mapper's own gateway comment lists them as «not-actually-paid»), yet they keep the cash-term default and are
     * settled at issue with no payment. (Auto-issue only holds whmcsIsUnpaid(), so these can even be filed unattended.)
     */
    #[DataProvider('notPaidStatuses')]
    public function test_adv2_other_not_paid_statuses_also_stay_open_receivables(string $status): void
    {
        $this->assertSame($this->credit->id, $this->methodFor($this->row($status)), "WHMCS '{$status}' is not paid");
    }

    /** @return array<string, array{string}> */
    public static function notPaidStatuses(): array
    {
        return ['Collections' => ['Collections'], 'Payment Pending' => ['Payment Pending']];
    }

    /**
     * ADV-3 (P2). Unpaid with account CREDIT already applied (WHMCS/MyCS `credit` 10.00, `total` = 14.80 still due).
     * The invoice is now an open receivable — for the full 24.80: the 10.00 the customer already paid in credit is
     * never recorded, so the Καρτέλα / reminders / dashboard demand money already received.
     */
    public function test_adv3_credit_applied_before_issue_is_not_owed_again(): void
    {
        $this->markTestSkipped('Owner decision (2026-10-27): warning only — the preview shows what was paid before issue; staff record it by hand. See WhmcsUnpaidCreditTermTest::test_the_preview_warns_about_what_was_paid_before_issue.');
        $row = $this->row('Unpaid', ['total' => '14.80', 'credit' => '10.00']);
        $invoice = app(WhmcsInvoiceFiler::class)->file($this->tenant, $row, $this->customer, $this->invoiceType)->invoice;
        $invoice = $this->issued($invoice);

        $balance = app(InvoiceBalance::class)->for($invoice->fresh());
        $this->assertEqualsWithDelta(14.80, $balance->balance, 0.001, 'only what is still due at the source is a receivable');
    }

    /**
     * ADV-4 (P2). Unpaid but PARTIALLY paid at the source (a 10.00 transaction; WHMCS `balance` 14.80). Same as ADV-3:
     * the partial payment is not recorded at issue, so the open receivable is the full 24.80.
     */
    public function test_adv4_a_partial_payment_before_issue_is_not_owed_again(): void
    {
        $this->markTestSkipped('Owner decision (2026-10-27): warning only — the preview shows what was paid before issue; staff record it by hand. See WhmcsUnpaidCreditTermTest::test_the_preview_warns_about_what_was_paid_before_issue.');
        $row = $this->row('Unpaid', [
            'balance' => '14.80',
            'transactions' => ['transaction' => [['transid' => 'T-1', 'gateway' => 'banktransfer', 'amountin' => '10.00', 'amountout' => '0.00']]],
        ]);
        $invoice = app(WhmcsInvoiceFiler::class)->file($this->tenant, $row, $this->customer, $this->invoiceType)->invoice;
        $invoice = $this->issued($invoice);

        $balance = app(InvoiceBalance::class)->for($invoice->fresh());
        $this->assertEqualsWithDelta(14.80, $balance->balance, 0.001, 'the 10.00 already paid is not owed again');
    }

    /**
     * PIN (passes): MyCS sends 'Unpaid', but a lower-case 'unpaid' (strcasecmp) and a paid invoice with an unmapped
     * gateway behave as intended — credit-term for the former, the type's cash default for the latter.
     */
    public function test_pin_lowercase_unpaid_is_credit_term_and_paid_stays_cash_term(): void
    {
        $this->assertSame($this->credit->id, $this->methodFor($this->row('unpaid', [], 7101)));
        $this->assertSame($this->cash->id, $this->methodFor($this->row('Paid', ['paymentmethod' => 'unknowngw'], 7102)));
    }
}
