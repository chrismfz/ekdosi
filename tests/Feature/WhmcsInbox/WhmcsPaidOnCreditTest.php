<?php

namespace Tests\Feature\WhmcsInbox;

use App\Enums\PaymentStatus;
use App\Filament\Resources\WhmcsInbox\Pages\ListWhmcsInbox;
use App\Models\Company;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\InvoiceType;
use App\Models\Payment;
use App\Models\PaymentMethod;
use App\Models\PendingWhmcsInvoice;
use App\Models\User;
use App\Models\VatCategory;
use App\Services\InvoiceBalance;
use App\Services\Whmcs\WhmcsPaymentReconciler;
use App\Services\Whmcs\WhmcsPaymentSyncer;
use App\Services\WhmcsInbox\WhmcsInvoiceFiler;
use App\Services\WhmcsInbox\WhmcsInvoiceMapper;
use App\Services\WhmcsInbox\WhmcsInvoiceSplitter;
use App\Services\WhmcsInbox\WhmcsReceiptRecorder;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * «Πληρωμένο στο WHMCS ≠ εισπραγμένο» (ΤΠΥ7187 / WHMCS #32722). Some customers' WHMCS invoices are marked Paid only
 * so WHMCS keeps the service running — no money in yet. Issued with «Δεν έχει πληρωθεί ακόμα — έκδοση επί πιστώσει»:
 * the «Επί Πιστώσει» method, no receipt, and the payment sync never takes that WHMCS «Paid» for a payment. A customer
 * flagged for it is never auto-issued and gets the box pre-ticked.
 */
class WhmcsPaidOnCreditTest extends TestCase
{
    use RefreshDatabase;

    private Company $tenant;

    private PaymentMethod $cash;

    private PaymentMethod $credit;

    private InvoiceType $type;

    private Customer $customer;

    private const PAID = [
        'status' => 'Paid',
        'datepaid' => '2026-11-11 15:32:01',
        'paymentmethod' => 'banktransfer',
        'transactions' => ['transaction' => [
            ['id' => 1, 'gateway' => 'banktransfer', 'transid' => '', 'date' => '2026-11-11 15:32:01', 'amountin' => '124.00', 'amountout' => '0.00'],
        ]],
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = Company::create([
            'name' => 'MyIP', 'slug' => 'poc-'.uniqid(), 'country_code' => 'GR', 'einvoice_provider' => 'gr-mydata', 'mydata_mode' => 'off',
        ]);
        VatCategory::create(['company_id' => $this->tenant->id, 'name' => '24%', 'rate' => 24.00, 'is_default' => true]);
        $this->cash = PaymentMethod::create(['company_id' => $this->tenant->id, 'description' => 'Ηλεκτρονικά μέσα', 'due_days' => 0, 'mydata_payment_type' => 7]);
        $this->credit = PaymentMethod::create(['company_id' => $this->tenant->id, 'description' => 'Επί Πιστώσει', 'due_days' => 10, 'mydata_payment_type' => 5]);
        $this->type = InvoiceType::create([
            'company_id' => $this->tenant->id, 'name' => 'ΤΠΥ', 'code' => 'ΤΠΥ', 'invcount' => 1, 'payment_method_id' => $this->cash->id,
        ]);
        $this->customer = Customer::create(['company_id' => $this->tenant->id, 'name' => 'Digit', 'afm' => '073090583']);
    }

    /** @param array<string, mixed> $extra */
    private function row(array $extra = self::PAID, int $id = 32722): PendingWhmcsInvoice
    {
        return PendingWhmcsInvoice::create([
            'company_id' => $this->tenant->id,
            'whmcs_invoice_id' => $id,
            'payload' => array_merge([
                'invoiceid' => $id, 'userid' => 82, 'date' => '2026-11-10', 'total' => '124.00',
                'items' => ['item' => [['description' => 'Hosting', 'amount' => '124.00', 'taxed' => '1']]],
            ], $extra),
            'match_reason' => PendingWhmcsInvoice::REASON_LINKED,
            'status' => PendingWhmcsInvoice::STATUS_PENDING_REVIEW,
            'customer_id' => $this->customer->id,
        ]);
    }

    private function operator(): void
    {
        Gate::before(fn () => true);
        $user = User::create(['name' => 'Op', 'email' => 'op-'.uniqid().'@t.local', 'password' => bcrypt('x')]);
        $user->companies()->attach($this->tenant->id);
        $this->actingAs($user);
        Filament::setTenant($this->tenant);
    }

    /** Issue a row as the operator would after the draft: file it (off mode) and mark it active. */
    private function issue(PendingWhmcsInvoice $row): Invoice
    {
        $invoice = app(WhmcsInvoiceFiler::class)->file($this->tenant, $row, $this->customer, $this->type)->invoice;
        Invoice::query()->whereKey($invoice->id)->update(['local_status' => 'active']);

        return $invoice->fresh();
    }

    private function waive(PendingWhmcsInvoice $row, string $at = '2026-11-11 15:35:00'): PendingWhmcsInvoice
    {
        $row->forceFill(['whmcs_paid_waived_at' => $at])->save();

        return $row->fresh();
    }

    // --- the mapping + issue: on credit, no receipt -------------------------------------------------

    public function test_a_waived_paid_row_takes_the_credit_method_over_the_gateway_one(): void
    {
        $map = fn (PendingWhmcsInvoice $r) => app(WhmcsInvoiceMapper::class)
            ->map($this->tenant->fresh(), $r, $this->customer, $this->type)['header']['payment_method_id'];

        $this->assertSame($this->cash->id, $map($this->row()), 'a plain Paid row is settled at issue');
        $this->assertSame($this->credit->id, $map($this->waive($this->row(id: 32723))));
    }

    public function test_issued_on_credit_records_no_receipt_and_stays_open(): void
    {
        $invoice = $this->issue($this->waive($this->row()));

        $this->assertSame($this->credit->id, $invoice->payment_method_id);
        $this->assertSame(0, Payment::where('invoice_id', $invoice->id)->count(), 'no receipt for a WHMCS «Paid» that is not money');
        $balance = app(InvoiceBalance::class)->for($invoice);
        $this->assertSame(PaymentStatus::Unpaid, $balance->status);
        $this->assertEqualsWithDelta(124.0, $balance->balance, 0.001);
    }

    public function test_a_plain_paid_row_still_records_its_receipt(): void
    {
        $invoice = $this->issue($this->row());

        $this->assertSame(1, Payment::where('invoice_id', $invoice->id)->count(), 'regression: the normal paid path is unchanged');
    }

    public function test_the_recorder_alone_skips_a_waived_row(): void
    {
        $row = $this->waive($this->row());
        $invoice = $this->issue($row);

        $this->assertSame(0.0, app(WhmcsReceiptRecorder::class)->recordIfPaid($row->fresh(), $invoice));
    }

    // --- the payment sync + worklist ignore the waived settlement --------------------------------------

    public function test_the_sync_ignores_the_waived_settlement_but_takes_a_later_real_one(): void
    {
        $row = $this->waive($this->row());
        $this->issue($row);
        $row = $row->fresh();
        $syncer = app(WhmcsPaymentSyncer::class);

        $same = $syncer->syncTenant($this->tenant, fn (int $id) => self::PAID + ['userid' => 82]);
        $this->assertSame(0, $same->recorded, 'the WHMCS «Paid» that was declared not money');
        $this->assertSame([], app(WhmcsPaymentReconciler::class)->reconcile($this->tenant, fn (int $id) => self::PAID + ['userid' => 82]), 'nor a worklist item');

        // WHMCS unwound (Mark Unpaid) and the real money entered later.
        $later = ['status' => 'Paid', 'datepaid' => '2026-11-25 10:00:00', 'userid' => 82];
        $this->assertSame([$row->invoice_id], app(WhmcsPaymentReconciler::class)->reconcile($this->tenant, fn (int $id) => $later));
        $this->assertSame(1, $syncer->syncTenant($this->tenant, fn (int $id) => $later)->recorded);
    }

    public function test_the_sync_treats_a_waived_row_without_datepaid_as_waived(): void
    {
        $row = $this->waive($this->row());
        $this->issue($row);

        $result = app(WhmcsPaymentSyncer::class)->syncTenant($this->tenant, fn (int $id) => ['status' => 'Paid', 'userid' => 82]);

        $this->assertSame(0, $result->recorded);
    }

    // --- the inbox modal ------------------------------------------------------------------------------

    public function test_the_modal_box_issues_the_draft_on_credit(): void
    {
        $this->operator();
        $row = $this->row();

        Livewire::test(ListWhmcsInbox::class)
            ->callTableAction('create_draft', $row, data: [
                'customer_id' => $this->customer->id, 'invoice_type_id' => $this->type->id, 'whmcs_paid_on_credit' => true,
            ]);

        $invoice = Invoice::firstOrFail();
        $this->assertSame($this->credit->id, $invoice->payment_method_id);
        $this->assertNotNull($row->fresh()->whmcs_paid_waived_at);
    }

    public function test_the_modal_without_the_box_keeps_the_paid_path(): void
    {
        $this->operator();
        $row = $this->row();

        Livewire::test(ListWhmcsInbox::class)
            ->callTableAction('create_draft', $row, data: [
                'customer_id' => $this->customer->id, 'invoice_type_id' => $this->type->id, 'whmcs_paid_on_credit' => false,
            ]);

        $this->assertSame($this->cash->id, Invoice::firstOrFail()->payment_method_id);
        $this->assertNull($row->fresh()->whmcs_paid_waived_at);
    }

    public function test_the_box_is_pre_ticked_for_a_flagged_customer_only(): void
    {
        $this->operator();
        $plain = $this->row();
        Livewire::test(ListWhmcsInbox::class)
            ->mountTableAction('create_draft', $plain)
            ->assertTableActionDataSet(['whmcs_paid_on_credit' => false]);

        $this->customer->update(['whmcs_paid_on_credit' => true]);
        Livewire::test(ListWhmcsInbox::class)
            ->mountTableAction('create_draft', $plain)
            ->assertTableActionDataSet(['whmcs_paid_on_credit' => true]);
    }

    public function test_the_box_refuses_without_a_credit_method(): void
    {
        $this->operator();
        $this->credit->delete();
        $row = $this->row();

        Livewire::test(ListWhmcsInbox::class)
            ->callTableAction('create_draft', $row, data: [
                'customer_id' => $this->customer->id, 'invoice_type_id' => $this->type->id, 'whmcs_paid_on_credit' => true,
            ]);

        $this->assertSame(0, Invoice::count(), 'it would have been issued settled');
        $this->assertNull($row->fresh()->whmcs_paid_waived_at);
    }

    public function test_a_failed_draft_does_not_leave_the_row_waived(): void
    {
        $this->operator();
        $row = $this->row(self::PAID + ['currencycode' => 'USD']);   // createDraft refuses a non-EUR row

        Livewire::test(ListWhmcsInbox::class)
            ->callTableAction('create_draft', $row, data: [
                'customer_id' => $this->customer->id, 'invoice_type_id' => $this->type->id, 'whmcs_paid_on_credit' => true,
            ]);

        $this->assertSame(0, Invoice::count());
        $this->assertNull($row->fresh()->whmcs_paid_waived_at);
    }

    public function test_the_box_is_ignored_on_an_unpaid_row(): void
    {
        $this->operator();
        $row = $this->row(['status' => 'Unpaid']);

        Livewire::test(ListWhmcsInbox::class)
            ->callTableAction('create_draft', $row, data: [
                'customer_id' => $this->customer->id, 'invoice_type_id' => $this->type->id, 'whmcs_paid_on_credit' => true,
            ]);

        $this->assertSame($this->credit->id, Invoice::firstOrFail()->payment_method_id, 'unpaid is on credit anyway');
        $this->assertNull($row->fresh()->whmcs_paid_waived_at, 'nothing to waive: WHMCS reports no payment');
    }

    // --- independent review (round 1) -----------------------------------------------------------------

    /** R1-1: flagged customer, issued UNPAID first (no waiver), then marked Paid in WHMCS only to stop a suspension. */
    public function test_a_flagged_customers_later_fake_paid_is_never_synced(): void
    {
        $this->customer->update(['whmcs_paid_on_credit' => true]);
        $invoice = $this->issue($this->row(['status' => 'Unpaid']));
        $this->assertSame($this->credit->id, $invoice->payment_method_id, 'precondition: on credit');
        $later = ['status' => 'Paid', 'datepaid' => '2026-11-25 10:00:00', 'userid' => 82];

        $this->assertSame(0, app(WhmcsPaymentSyncer::class)->syncTenant($this->tenant, fn (int $id) => $later)->recorded);
        $this->assertSame(0.0, app(WhmcsPaymentSyncer::class)->syncInvoice($invoice, fn (int $id) => $later));
        $this->assertSame([], app(WhmcsPaymentReconciler::class)->reconcile($this->tenant, fn (int $id) => $later));
        $this->assertSame(0, Payment::where('invoice_id', $invoice->id)->count());
    }

    /** R1-2: the split path takes the box too — every party's draft on credit, no receipt at issue. */
    public function test_split_with_the_box_drafts_every_party_on_credit(): void
    {
        $row = PendingWhmcsInvoice::create([
            'company_id' => $this->tenant->id, 'whmcs_invoice_id' => 1234, 'whmcs_userid' => 82,
            'customer_id' => $this->customer->id,
            'payload' => self::PAID + [
                'invoiceid' => 1234, 'userid' => 82, 'total' => 24.80, 'currencycode' => 'EUR', 'date' => '2026-11-10',
                'items' => ['item' => [
                    ['id' => 11, 'description' => 'domain a.gr', 'amount' => '12.40', 'taxed' => 1],
                    ['id' => 22, 'description' => 'hosting plan', 'amount' => '12.40', 'taxed' => 1],
                ]],
            ],
            'match_reason' => PendingWhmcsInvoice::REASON_LINKED,
            'third_party_state' => PendingWhmcsInvoice::TP_MULTI,
            'third_party_resolution' => ['whmcs_invoice_id' => 1234, 'whmcs_userid' => 82, 'multi_party' => true, 'lines' => [
                ['item_id' => 11, 'routed' => true, 'is_receipt' => false, 'contact' => ['id' => 5, 'company_name' => 'Haris', 'gr_vatno' => '081951154']],
                ['item_id' => 22, 'routed' => false, 'is_receipt' => false, 'contact' => null],
            ]],
            'status' => PendingWhmcsInvoice::STATUS_HELD,
        ]);

        $invoices = app(WhmcsInvoiceSplitter::class)->split($this->tenant, $row, $this->type, paidOnCredit: true);

        $this->assertCount(2, $invoices);
        foreach ($invoices as $invoice) {
            $this->assertSame($this->credit->id, $invoice->payment_method_id);
        }
        $this->assertNotNull($row->fresh()->whmcs_paid_waived_at);
    }

    /** R1-3: a real gateway transaction (card/vPOS ref) is evidence of money — the box is not pre-ticked. */
    public function test_the_box_is_not_pre_ticked_when_whmcs_shows_a_real_gateway_payment(): void
    {
        $this->operator();
        $this->customer->update(['whmcs_paid_on_credit' => true]);
        $row = $this->row(['status' => 'Paid', 'datepaid' => '2026-11-11 15:32:01', 'paymentmethod' => 'eurobanklib',
            'transactions' => ['transaction' => [['gateway' => 'eurobanklib', 'transid' => '123456789012', 'amountin' => '124.00']]]]);

        Livewire::test(ListWhmcsInbox::class)
            ->mountTableAction('create_draft', $row)
            ->assertTableActionDataSet(['whmcs_paid_on_credit' => false]);
    }

    /** Review r2-1: delete the on-credit draft, restore it, issue it → still no receipt (the waiver is kept). */
    public function test_a_deleted_then_restored_on_credit_draft_still_records_no_receipt(): void
    {
        $row = $this->row();
        $invoice = app(WhmcsInvoiceFiler::class)->createDraft($this->tenant, $row, $this->customer, $this->type, paidOnCredit: true);

        $invoice->delete();
        $this->assertSame(PendingWhmcsInvoice::STATUS_PENDING_REVIEW, $row->fresh()->status, 'precondition: released');
        $invoice->restore();
        $this->assertSame($invoice->id, $row->fresh()->invoice_id, 'precondition: relinked');

        $this->assertNotNull($row->fresh()->whmcs_paid_waived_at);
        $this->assertSame(0.0, app(WhmcsReceiptRecorder::class)->recordIfPaid(
            tap($row->fresh(), fn ($r) => $r->status = PendingWhmcsInvoice::STATUS_FILED),
            $invoice->fresh(),
        ));
    }

    /** …and a NEW draft after the delete decides afresh from its own box. */
    public function test_a_new_draft_after_delete_overwrites_the_kept_waiver(): void
    {
        $row = $this->row();
        app(WhmcsInvoiceFiler::class)->createDraft($this->tenant, $row, $this->customer, $this->type, paidOnCredit: true)->delete();

        $second = app(WhmcsInvoiceFiler::class)->createDraft($this->tenant, $row->fresh(), $this->customer, $this->type, paidOnCredit: false);

        $this->assertNull($row->fresh()->whmcs_paid_waived_at);
        $this->assertSame($this->cash->id, $second->payment_method_id);
    }

    public function test_create_draft_without_a_choice_leaves_the_row_alone(): void
    {
        $row = $this->waive($this->row());

        app(WhmcsInvoiceFiler::class)->createDraft($this->tenant, $row, $this->customer, $this->type);

        $this->assertNotNull($row->fresh()->whmcs_paid_waived_at, 'null = keep the row as it is (other callers)');
    }

    // --- auto-issue -----------------------------------------------------------------------------------

    public function test_auto_issue_holds_a_flagged_customers_paid_row(): void
    {
        $this->tenant->forceFill([
            'whmcs_api_url' => 'https://whmcs.example.test/includes/api.php', 'whmcs_api_identifier' => 'id', 'whmcs_api_secret' => 'secret',
            'whmcs_auto_issue_immediate' => true, 'whmcs_default_invoice_type_id' => $this->type->id,
        ])->save();
        $this->customer->update(['needs_immediate_invoice' => true, 'whmcs_paid_on_credit' => true]);
        $flagged = $this->row();
        // Control: the same tenant + an unflagged άμεση customer IS auto-issued (so the hold is the flag, not a dead run).
        $other = Customer::create(['company_id' => $this->tenant->id, 'name' => 'Άλλος', 'afm' => '111111112', 'needs_immediate_invoice' => true]);
        $control = $this->row(id: 32724);
        $control->update(['customer_id' => $other->id]);

        $this->artisan('whmcs:auto-issue')->assertExitCode(0);

        $this->assertNull($flagged->fresh()->invoice_id, 'never auto-issued as paid');
        $this->assertNotNull($control->fresh()->invoice_id, 'control: the unflagged άμεση customer is auto-issued');
    }
}
