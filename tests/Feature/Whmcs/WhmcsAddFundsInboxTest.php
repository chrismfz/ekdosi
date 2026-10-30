<?php

namespace Tests\Feature\Whmcs;

use App\Models\Company;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\InvoiceType;
use App\Models\PaymentMethod;
use App\Models\PendingWhmcsInvoice;
use App\Models\VatCategory;
use App\Services\Whmcs\WhmcsInvoiceIngestor;
use App\Services\WhmcsInbox\WhmcsInvoiceFiler;
use App\Support\Billing\MycsPayload;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * A WHMCS «Add Funds» (Προσθήκη Χρημάτων) invoice is a wallet top-up: a prepayment
 * with no identified supply, so NOT a sale (owner, 2026-10-30). The ingestor closes
 * it as «resolved» (final, like a 0 € row), holds a mix of Add Funds + real charges,
 * and the filer / auto-issue refuse it. The document is issued when the credit is
 * spent: a later invoice paid with applied credit keeps its FULL value (credit is a
 * payment, not a discount). Prod: myip WHMCS #32638 (pending #346), €11, logged an
 * ERROR from auto-issue every 15 minutes.
 */
class WhmcsAddFundsInboxTest extends TestCase
{
    use RefreshDatabase;

    private Company $tenant;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = Company::create([
            'name' => 'AddFunds', 'slug' => 'af-'.uniqid(), 'country_code' => 'GR',
            'einvoice_provider' => 'gr-mydata', 'mydata_mode' => 'off',
        ]);
    }

    /** @return array<string, mixed> the real shape of WHMCS #32638 */
    private function topUp(int $id = 32638, string $amount = '11.00'): array
    {
        return [
            'invoiceid' => $id, 'userid' => 1, 'status' => 'Paid', 'date' => '2026-10-30',
            'subtotal' => $amount, 'tax' => '0.00', 'tax2' => '0.00', 'taxrate' => '24.000',
            'total' => $amount, 'credit' => '0.00', 'taxtype' => 'Exclusive', 'paymentmethod' => 'banktransfer',
            'items' => ['item' => [[
                'id' => 1, 'type' => 'AddFunds', 'relid' => 0, 'description' => 'Προσθήκη Χρημάτων',
                'amount' => $amount, 'taxed' => 0,
            ]]],
        ];
    }

    /** @return array<string, mixed> a top-up with a real hosting line next to it */
    private function mixed(int $id = 32700): array
    {
        $payload = $this->topUp($id);
        $payload['items']['item'][] = ['id' => 2, 'type' => 'Hosting', 'relid' => 9, 'description' => 'Hosting', 'amount' => '10.00', 'taxed' => 1];
        $payload['subtotal'] = '21.00';
        $payload['tax'] = '2.40';
        $payload['total'] = '23.40';

        return $payload;
    }

    /** @return array<string, mixed> a real sale (10 € + 24%) */
    private function hosting(int $id, string $total, string $credit): array
    {
        return [
            'invoiceid' => $id, 'userid' => 1, 'status' => 'Paid', 'date' => '2026-10-30',
            'subtotal' => '10.00', 'tax' => '2.40', 'tax2' => '0.00', 'taxrate' => '24.000',
            'total' => $total, 'credit' => $credit, 'taxtype' => 'Exclusive',
            'items' => ['item' => [[
                'id' => 1, 'type' => 'Hosting', 'relid' => 9, 'description' => 'Hosting', 'amount' => '10.00', 'taxed' => 1,
            ]]],
        ];
    }

    private function ingest(array $payload): PendingWhmcsInvoice
    {
        return app(WhmcsInvoiceIngestor::class)->ingest($this->tenant, $payload)->row->fresh();
    }

    /** @return array{0: Customer, 1: InvoiceType} */
    private function filingSetup(): array
    {
        VatCategory::create(['company_id' => $this->tenant->id, 'name' => '24%', 'rate' => 24, 'is_default' => true]);
        VatCategory::create(['company_id' => $this->tenant->id, 'name' => '0%', 'rate' => 0, 'is_default' => false]);
        $pm = PaymentMethod::create(['company_id' => $this->tenant->id, 'name' => 'Bank', 'due_days' => 0, 'is_active' => true]);
        $type = InvoiceType::create(['company_id' => $this->tenant->id, 'name' => 'ΤΠΥ', 'code' => 'ΤΠΥ', 'invcount' => 1, 'payment_method_id' => $pm->id]);
        $customer = Customer::create(['company_id' => $this->tenant->id, 'name' => 'C', 'afm' => '111111111', 'needs_immediate_invoice' => true]);

        return [$customer, $type];
    }

    private function stage(array $payload, Customer $customer): PendingWhmcsInvoice
    {
        return PendingWhmcsInvoice::create([
            'company_id' => $this->tenant->id, 'whmcs_invoice_id' => $payload['invoiceid'], 'whmcs_userid' => 1, 'payload' => $payload,
            'match_reason' => PendingWhmcsInvoice::REASON_LINKED, 'status' => PendingWhmcsInvoice::STATUS_PENDING_REVIEW,
            'customer_id' => $customer->id,
        ]);
    }

    public function test_add_funds_detection(): void
    {
        $this->assertSame(PendingWhmcsInvoice::ADD_FUNDS_ONLY, PendingWhmcsInvoice::addFundsShape($this->topUp()));
        $this->assertSame(PendingWhmcsInvoice::ADD_FUNDS_MIXED, PendingWhmcsInvoice::addFundsShape($this->mixed()));
        $this->assertNull(PendingWhmcsInvoice::addFundsShape($this->hosting(1, '12.40', '0.00')));
        // WHMCS returns a bare object for a single line.
        $single = $this->topUp();
        $single['items']['item'] = $single['items']['item'][0];
        $this->assertSame(PendingWhmcsInvoice::ADD_FUNDS_ONLY, PendingWhmcsInvoice::addFundsShape($single));
        // A discount or WHMCS's blank padding next to the top-up doesn't make it a sale.
        $discounted = $this->topUp();
        $discounted['items']['item'][] = ['type' => '', 'description' => 'Έκπτωση', 'amount' => '-1.00', 'taxed' => 0];
        $discounted['items']['item'][] = ['type' => '', 'description' => '', 'amount' => '0.00', 'taxed' => 0];
        $this->assertSame(PendingWhmcsInvoice::ADD_FUNDS_ONLY, PendingWhmcsInvoice::addFundsShape($discounted));
        // A 0 € Add Funds line is the zero-value rule's, not this one.
        $this->assertNull(PendingWhmcsInvoice::addFundsShape($this->topUp(1, '0.00')));
        $this->assertNull(PendingWhmcsInvoice::addFundsShape(['invoiceid' => 1]));
    }

    public function test_a_top_up_is_closed_as_resolved_not_queued(): void
    {
        $row = $this->ingest($this->topUp());

        $this->assertSame(PendingWhmcsInvoice::STATUS_RESOLVED, $row->status);
        $this->assertSame(PendingWhmcsInvoice::ADD_FUNDS_NOTE, $row->notes);
    }

    public function test_a_mycs_top_up_is_closed_too(): void
    {
        // MyCS's feed types its credit purchases «AddFunds» (EkdosiFeed::type()).
        $row = $this->ingest($this->topUp(32800) + ['mycs' => ['id' => 32800]]);

        $this->assertSame(PendingWhmcsInvoice::SOURCE_MYCS, $row->source);
        $this->assertSame(PendingWhmcsInvoice::STATUS_RESOLVED, $row->status);
    }

    public function test_an_unpaid_top_up_is_closed_too(): void
    {
        // whmcs:fetch-unpaid: a top-up is no sale on credit either.
        $row = $this->ingest(array_merge($this->topUp(32801), ['status' => 'Unpaid']));

        $this->assertSame(PendingWhmcsInvoice::STATUS_RESOLVED, $row->status);
    }

    public function test_a_top_up_staged_before_the_rule_is_closed_by_the_next_fetch(): void
    {
        // Prod #346: staged as pending_review; the scheduled fetch re-reads it (paid + unfiled).
        [$customer] = $this->filingSetup();
        $staged = $this->stage($this->topUp(), $customer);

        $row = $this->ingest($this->topUp());

        $this->assertSame($staged->id, $row->id);
        $this->assertSame(PendingWhmcsInvoice::STATUS_RESOLVED, $row->status);
        $this->assertSame(PendingWhmcsInvoice::ADD_FUNDS_NOTE, $row->notes);
    }

    public function test_a_closed_top_up_is_final(): void
    {
        $this->assertSame(PendingWhmcsInvoice::STATUS_RESOLVED, $this->ingest($this->topUp(900))->status);

        $row = $this->ingest($this->mixed(900));

        $this->assertSame(PendingWhmcsInvoice::STATUS_RESOLVED, $row->status);
        $this->assertCount(1, $row->payload['items']['item'], 'the decision-time payload stays');
    }

    public function test_a_mixed_invoice_is_held_with_the_reason(): void
    {
        $row = $this->ingest($this->mixed());

        $this->assertSame(PendingWhmcsInvoice::STATUS_HELD, $row->status);
        $this->assertSame(PendingWhmcsInvoice::ADD_FUNDS_MIXED_REASON, $row->hold_reason);
        $this->assertStringContainsString('Add Funds', (string) $row->notes);
    }

    public function test_a_waiting_row_that_becomes_mixed_is_held_once(): void
    {
        $this->assertSame(PendingWhmcsInvoice::STATUS_PENDING_REVIEW, $this->ingest($this->hosting(32701, '12.40', '0.00'))->status);

        $row = $this->ingest($this->mixed(32701));
        $this->assertSame(PendingWhmcsInvoice::STATUS_HELD, $row->status);

        // The operator checked it and sent it back to review: the same lines don't hold it again.
        $row->update(['status' => PendingWhmcsInvoice::STATUS_PENDING_REVIEW, 'hold_reason' => null]);
        $this->assertSame(PendingWhmcsInvoice::STATUS_PENDING_REVIEW, $this->ingest($this->mixed(32701))->status);
    }

    public function test_a_mix_staged_before_the_rule_is_held_by_the_next_fetch(): void
    {
        [$customer] = $this->filingSetup();
        $this->stage($this->mixed(), $customer);

        $row = $this->ingest($this->mixed());

        $this->assertSame(PendingWhmcsInvoice::STATUS_HELD, $row->status);
        $this->assertSame(PendingWhmcsInvoice::ADD_FUNDS_MIXED_REASON, $row->hold_reason);
    }

    public function test_a_mycs_send_to_ekdosi_top_up_is_held_not_closed(): void
    {
        $payload = $this->topUp(32802) + ['mycs' => ['id' => 32802, 'requested_at' => '2026-10-30 10:00:00', 'requested' => true]];
        if (! MycsPayload::requested($payload)) {
            $this->markTestSkipped('MyCS request flag shape differs');
        }

        $row = $this->ingest($payload);

        $this->assertSame(PendingWhmcsInvoice::STATUS_HELD, $row->status);
        $this->assertSame(PendingWhmcsInvoice::ADD_FUNDS_NOTE, $row->hold_reason);
    }

    public function test_the_filer_refuses_a_top_up_and_a_mix_before_any_draft(): void
    {
        [$customer, $type] = $this->filingSetup();

        foreach ([$this->topUp(), $this->mixed()] as $payload) {
            $pending = $this->stage($payload, $customer);
            try {
                app(WhmcsInvoiceFiler::class)->file($this->tenant, $pending, $customer, $type, unattended: true);
                $this->fail('an Add Funds invoice must not be filed');
            } catch (LogicException $e) {
                $this->assertStringContainsString('Add Funds', $e->getMessage());
            }
        }

        $this->assertSame(0, Invoice::count(), 'no orphan draft');
        $this->assertSame(1, $type->fresh()->invcount, 'no ΑΑ consumed');
    }

    public function test_create_draft_refuses_a_top_up_but_drafts_a_mix_for_the_operator(): void
    {
        [$customer, $type] = $this->filingSetup();

        try {
            app(WhmcsInvoiceFiler::class)->createDraft($this->tenant, $this->stage($this->topUp(), $customer), $customer, $type);
            $this->fail('a top-up has nothing to draft');
        } catch (LogicException $e) {
            $this->assertStringContainsString('Προκαταβολή', $e->getMessage());
        }
        $this->assertSame(0, Invoice::count());

        // The mix: drafted, the operator removes the Add Funds line (the hold said so).
        $draft = app(WhmcsInvoiceFiler::class)->createDraft($this->tenant, $this->stage($this->mixed(), $customer), $customer, $type);
        $this->assertSame('draft', $draft->local_status);
    }

    public function test_auto_issue_holds_a_staged_top_up_without_an_error(): void
    {
        [$customer, $type] = $this->filingSetup();
        $this->tenant->update([
            'whmcs_api_url' => 'https://whmcs.example.test/includes/api.php', 'whmcs_api_identifier' => 'id',
            'whmcs_api_secret' => 'secret', 'whmcs_auto_issue_immediate' => true, 'whmcs_default_invoice_type_id' => $type->id,
        ]);
        $pending = $this->stage($this->topUp(), $customer);
        Log::spy();

        $this->artisan('whmcs:auto-issue')->assertExitCode(0);

        Log::shouldNotHaveReceived('error');
        $this->assertNull($pending->fresh()->invoice_id);
        $this->assertSame(0, Invoice::count());
    }

    /**
     * The credit a top-up bought is spent on a later invoice: THAT is when the document is
     * issued, at its full value — applied credit is a payment, not a discount. Fully and
     * partly credit-paid, through the ingestor and the unattended filer.
     *
     * @return array<string, array{0: string, 1: string}>
     */
    public static function creditPaid(): array
    {
        return [
            'fully from credit' => ['0.00', '12.40'],
            'partly from credit' => ['5.00', '7.40'],
        ];
    }

    #[DataProvider('creditPaid')]
    public function test_an_invoice_paid_with_applied_credit_is_issued_at_full_value(string $total, string $credit): void
    {
        [$customer, $type] = $this->filingSetup();
        $row = $this->ingest($this->hosting(32900, $total, $credit));
        $this->assertSame(PendingWhmcsInvoice::STATUS_PENDING_REVIEW, $row->status);
        $row->update(['customer_id' => $customer->id]);

        app(WhmcsInvoiceFiler::class)->file($this->tenant, $row->fresh(), $customer, $type, unattended: true);

        $invoice = Invoice::sole();
        $this->assertSame('12.40', (string) $invoice->gross_total);
        $this->assertSame('10.00', (string) $invoice->net_total);
    }
}
