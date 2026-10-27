<?php

namespace Tests\Feature\Adversarial;

use App\Models\Company;
use App\Models\Customer;
use App\Models\InvoiceType;
use App\Models\PaymentMethod;
use App\Models\PendingWhmcsInvoice;
use App\Models\VatCategory;
use App\Services\WhmcsInbox\WhmcsInvoiceFiler;
use App\Services\WhmcsInbox\WhmcsInvoiceMapper;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Adversarial review of PR #704, round 2: the round-1 fixes in b327c35 (notPaidYet(), isCreditTerm() with deleted
 * methods, paidBeforeIssue() and the preview warnings). Setup mirrors Adv704Test.
 */
class Adv704Round2Test extends TestCase
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
    private function row(string $status, array $extra = [], int $id = 8001): PendingWhmcsInvoice
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

    private function previewHtml(PendingWhmcsInvoice $row): string
    {
        return view('filament.whmcs-inbox.preview-partial', ['preview' => app(WhmcsInvoiceFiler::class)
            ->preview($this->tenant->fresh(), $row, $this->customer, $this->invoiceType)])->render();
    }

    /**
     * R2-1 (P3). No «Επί Πιστώσει» in the tenant, an unpaid invoice with 10.00 of account credit applied. The document
     * is cash-term (red warning: «θα εκδοθεί εξοφλημένο»), so InvoiceBalance settles it at issue and nothing is owed.
     * The yellow warning under it still says «αλλιώς θα φαίνεται οφειλή όλο το ποσό» — the opposite of what happens:
     * the two warnings contradict each other. (Recording the 10.00 on a cash-term invoice is in fact what turns it into
     * a 14.80 receivable — hasRecordedPayments() — so the text should say that, or the yellow line should only claim
     * «full amount owed» on a credit-term document.)
     */
    public function test_r2_1_the_paid_before_issue_warning_does_not_claim_a_debt_on_a_cash_term_document(): void
    {
        $this->credit->delete();
        $html = $this->previewHtml($this->row('Unpaid', ['total' => '14.80', 'credit' => '10.00']));

        $this->assertStringContainsString('θα εκδοθεί εξοφλημένο', $html, 'precondition: cash-term, settled at issue');
        $this->assertStringNotContainsString('θα φαίνεται οφειλή όλο το ποσό', $html,
            'a document settled at issue is said to show the full amount as owed');
    }

    /**
     * R2-2 (P3). b327c35 makes WHMCS «Collections» and «Payment Pending» not-paid in the mapper (credit-term, preview
     * warnings), but whmcs:auto-issue still gates on whmcsIsUnpaid() (exact 'Unpaid'): such a row for an άμεση
     * τιμολόγηση customer is filed UNATTENDED, so nobody ever sees the paid-before-issue warning that is now the only
     * guard for ADV-3/4, and the «auto-issue is PAID-ONLY» contract (WhmcsAutoIssue::chooseType) is broken.
     * The gate should use the same not-paid set as the mapper (one shared predicate on PendingWhmcsInvoice).
     */
    #[DataProvider('notPaidStatuses')]
    public function test_r2_2_auto_issue_does_not_file_a_not_paid_row_unattended(string $status): void
    {
        $this->tenant->update([
            'whmcs_api_url' => 'https://whmcs.example.test/includes/api.php', 'whmcs_api_identifier' => 'id', 'whmcs_api_secret' => 'secret',
            'whmcs_auto_issue_immediate' => true, 'whmcs_default_invoice_type_id' => $this->invoiceType->id,
        ]);
        $this->customer->update(['needs_immediate_invoice' => true, 'afm' => '111111112']);
        $row = $this->row($status, ['total' => '14.80', 'credit' => '10.00', 'userid' => 1, 'date' => '2026-10-27'], 8201);
        $row->update(['customer_id' => $this->customer->id]);

        $this->artisan('whmcs:auto-issue')->assertExitCode(0);

        $this->assertNull($row->fresh()->invoice_id, "WHMCS '{$status}' (not paid, 10.00 already paid) was auto-issued with no operator");
    }

    /** @return array<string, array{string}> */
    public static function notPaidStatuses(): array
    {
        return ['Collections' => ['Collections'], 'Payment Pending' => ['Payment Pending']];
    }

    /**
     * PIN (passes). paidBeforeIssue() across the payload shapes it meets: WHMCS GetInvoice (one transaction as a bare
     * map, several as a list, amountin/amountout incl. a refund row), the ekdosi_bridge feed and MyCS (flat list with
     * amount[/refunded]), account credit, and malformed values. Never negative, never a crash, rounded to cents.
     *
     * @param  array<string, mixed>  $extra
     */
    #[DataProvider('paidShapes')]
    public function test_pin_paid_before_issue_reads_every_payload_shape(array $extra, float $expected): void
    {
        $source = app(WhmcsInvoiceMapper::class)
            ->map($this->tenant->fresh(), $this->row('Unpaid', $extra), $this->customer, $this->invoiceType)['source'];

        $this->assertEqualsWithDelta($expected, $source['whmcs_paid_before_issue'], 0.0001);
        $this->previewHtml($this->row('Unpaid', $extra, 8999)); // renders, whatever the payload
    }

    /** @return array<string, array{0: array<string, mixed>, 1: float}> */
    public static function paidShapes(): array
    {
        return [
            'whmcs single bare map' => [['transactions' => ['transaction' => ['amountin' => '10.00', 'amountout' => '0.00']]], 10.0],
            'whmcs list with refund row' => [['transactions' => ['transaction' => [
                ['amountin' => '10.00', 'amountout' => '0.00'], ['amountin' => '0.00', 'amountout' => '4.00'],
            ]]], 6.0],
            'whmcs empty string' => [['transactions' => ''], 0.0],
            'whmcs transaction null' => [['transactions' => ['transaction' => null]], 0.0],
            'feed / mycs flat list' => [['transactions' => [['amount' => '3.10'], ['amount' => '0.20', 'refunded' => '0.00']]], 3.3],
            'mycs refunded part' => [['transactions' => [['amount' => '10.00', 'refunded' => '2.50']]], 7.5],
            'refund exceeding' => [['transactions' => [['amount' => '1.00', 'refunded' => '9.00']]], 0.0],
            'credit plus payment' => [['credit' => '5.00', 'transactions' => [['amount' => '2.00']]], 7.0],
            'garbage rows' => [['credit' => null, 'transactions' => ['x', 7, null, ['amount' => 'abc']]], 0.0],
            'float drift' => [['transactions' => array_fill(0, 10, ['amount' => '0.10'])], 1.0],
        ];
    }

    /**
     * PIN (passes). A PAID invoice never carries a paid-before-issue figure (its own payments are the settlement) and
     * shows no unpaid warnings; an unpaid one with a live «Επί Πιστώσει» shows no red warning.
     */
    public function test_pin_paid_rows_carry_no_warning_and_credit_term_unpaid_has_no_red_one(): void
    {
        $paid = $this->row('Paid', ['credit' => '10.00', 'transactions' => [['amount' => '14.80']]], 8301);
        $source = app(WhmcsInvoiceMapper::class)->map($this->tenant->fresh(), $paid, $this->customer, $this->invoiceType)['source'];
        $this->assertSame(0.0, $source['whmcs_paid_before_issue']);
        $this->assertFalse($source['whmcs_unpaid']);
        $html = $this->previewHtml($paid);
        $this->assertStringNotContainsString('⚠️ Απλήρωτο', $html);
        $this->assertStringNotContainsString('Έχει ήδη πληρωθεί', $html);

        $html = $this->previewHtml($this->row('Unpaid', [], 8302));
        $this->assertStringContainsString('Επί Πιστώσει', $html);
        $this->assertStringNotContainsString('θα εκδοθεί εξοφλημένο', $html);
    }
}
