<?php

namespace Tests\Feature\WhmcsInbox;

use App\Models\Company;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\InvoiceType;
use App\Models\PaymentMethod;
use App\Models\PendingWhmcsInvoice;
use App\Models\VatCategory;
use App\Services\WhmcsInbox\WhmcsInvoiceSplitter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

/**
 * T-1c: the guided multi-party split — creates one draft invoice per billing
 * party from a multi-party pending row, all-or-nothing.
 */
class WhmcsInvoiceSplitterTest extends TestCase
{
    use RefreshDatabase;

    private Company $tenant;

    private InvoiceType $invoiceType;

    private Customer $reseller;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Company::create([
            'name' => 'T', 'slug' => 'spl-'.uniqid(), 'country_code' => 'GR',
            'einvoice_provider' => 'gr-mydata', 'mydata_mode' => 'off',
        ]);
        VatCategory::create([
            'company_id' => $this->tenant->id, 'name' => '24%', 'rate' => 24.00, 'is_default' => true,
        ]);
        $pm = PaymentMethod::create([
            'company_id' => $this->tenant->id, 'name' => 'Cash', 'due_days' => 0, 'is_active' => true,
        ]);
        $this->invoiceType = InvoiceType::create([
            'company_id' => $this->tenant->id, 'code' => 'TPY', 'name' => 'Τιμολόγιο',
            'invcount' => 0, 'payment_method_id' => $pm->id,
        ]);
        $this->reseller = Customer::create([
            'company_id' => $this->tenant->id, 'name' => 'Chris (reseller)',
            'afm' => '700700700', 'whmcs_client_id' => 793,
        ]);
    }

    private function multiPartyPending(): PendingWhmcsInvoice
    {
        // WHMCS invoice: line 1 → Haris (contact), line 2 → reseller's own.
        $payload = [
            'invoiceid' => 1234, 'userid' => 793, 'total' => 24.80, 'currencycode' => 'EUR',
            'date' => '2026-05-28',
            'items' => ['item' => [
                ['id' => 11, 'description' => 'domain a.gr', 'amount' => '12.40', 'taxed' => 1],
                ['id' => 22, 'description' => 'hosting plan', 'amount' => '12.40', 'taxed' => 1],
            ]],
        ];
        $resolution = [
            'whmcs_invoice_id' => 1234, 'whmcs_userid' => 793, 'multi_party' => true,
            'lines' => [
                ['item_id' => 11, 'routed' => true, 'is_receipt' => false,
                    'contact' => ['id' => 5, 'company_name' => 'Haris', 'gr_vatno' => '081951154']],
                ['item_id' => 22, 'routed' => false, 'is_receipt' => false, 'contact' => null],
            ],
        ];

        return PendingWhmcsInvoice::create([
            'company_id' => $this->tenant->id, 'whmcs_invoice_id' => 1234, 'whmcs_userid' => 793,
            'customer_id' => $this->reseller->id, 'payload' => $payload,
            'match_reason' => PendingWhmcsInvoice::REASON_LINKED,
            'third_party_state' => PendingWhmcsInvoice::TP_MULTI,
            'third_party_resolution' => $resolution,
            'status' => PendingWhmcsInvoice::STATUS_HELD,
        ]);
    }

    private function splitter(): WhmcsInvoiceSplitter
    {
        return app(WhmcsInvoiceSplitter::class);
    }

    public function test_splits_into_one_draft_per_party_with_correct_customers_and_lines(): void
    {
        $pending = $this->multiPartyPending();

        $invoices = $this->splitter()->split($this->tenant, $pending, $this->invoiceType);

        $this->assertCount(2, $invoices);

        // Haris draft: created customer by ΑΦΜ, the routed line only.
        $haris = Customer::where('company_id', $this->tenant->id)->where('afm', '081951154')->first();
        $this->assertNotNull($haris);
        $harisInvoice = collect($invoices)->firstWhere('customer_id', $haris->id);
        $this->assertNotNull($harisInvoice);
        $this->assertCount(1, $harisInvoice->lines);
        $this->assertSame('draft', $harisInvoice->local_status);
        $this->assertSame($pending->id, $harisInvoice->whmcs_pending_id);

        // Reseller draft: the reseller's own line.
        $resellerInvoice = collect($invoices)->firstWhere('customer_id', $this->reseller->id);
        $this->assertNotNull($resellerInvoice);
        $this->assertCount(1, $resellerInvoice->lines);

        // Distinct ΑΑ allocated per draft.
        $this->assertNotSame($harisInvoice->invcode, $resellerInvoice->invcode);

        // Pending row recorded as split, single-invoice link untouched.
        $pending->refresh();
        $this->assertSame(PendingWhmcsInvoice::STATUS_SPLIT, $pending->status);
        $this->assertNull($pending->invoice_id);
        $this->assertCount(2, $pending->splitInvoices);
    }

    public function test_receipt_group_routes_to_receipt_type_and_requires_it(): void
    {
        // Haris's line is flagged απόδειξη (is_receipt=true); the reseller's is not.
        $pending = $this->multiPartyPending();
        $res = $pending->third_party_resolution;
        $res['lines'][0]['is_receipt'] = true;
        $pending->update(['third_party_resolution' => $res]);

        // Without a receipt type → refuse (don't file a receipt routing as an invoice).
        try {
            $this->splitter()->split($this->tenant, $pending, $this->invoiceType);
            $this->fail('Expected a refusal when a receipt group has no receipt type.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('απόδειξη', $e->getMessage());
        }
        $this->assertSame(0, Invoice::where('whmcs_pending_id', $pending->id)->count());

        // With a receipt type → the receipt group files under it, the other under the invoice type.
        $receiptType = InvoiceType::create([
            'company_id' => $this->tenant->id, 'code' => 'APY', 'name' => 'Απόδειξη',
            'invcount' => 0, 'payment_method_id' => $this->invoiceType->payment_method_id,
        ]);

        $invoices = $this->splitter()->split($this->tenant, $pending->fresh(), $this->invoiceType, $receiptType);

        $haris = Customer::where('company_id', $this->tenant->id)->where('afm', '081951154')->first();
        $harisInvoice = collect($invoices)->firstWhere('customer_id', $haris->id);
        $resellerInvoice = collect($invoices)->firstWhere('customer_id', $this->reseller->id);
        $this->assertSame($receiptType->id, $harisInvoice->invoice_type_id, 'receipt group → receipt type');
        $this->assertSame($this->invoiceType->id, $resellerInvoice->invoice_type_id, 'invoice group → invoice type');
    }

    public function test_refuses_non_multi_party_rows(): void
    {
        $pending = $this->multiPartyPending();
        $pending->update(['third_party_state' => PendingWhmcsInvoice::TP_SINGLE]);

        $this->expectException(RuntimeException::class);
        $this->splitter()->split($this->tenant, $pending, $this->invoiceType);
    }

    public function test_is_atomic_when_a_party_cannot_resolve(): void
    {
        // Contact with no ΑΦΜ → unresolvable → whole split must abort,
        // leaving NO draft invoices behind.
        $pending = $this->multiPartyPending();
        $res = $pending->third_party_resolution;
        $res['lines'][0]['contact']['gr_vatno'] = '';   // strip Haris's ΑΦΜ
        $pending->update(['third_party_resolution' => $res]);

        try {
            $this->splitter()->split($this->tenant, $pending, $this->invoiceType);
            $this->fail('Expected a RuntimeException for the unresolvable party.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('ΑΦΜ', $e->getMessage());
        }

        $this->assertSame(0, Invoice::where('whmcs_pending_id', $pending->id)->count(), 'no partial split');
        $this->assertSame(PendingWhmcsInvoice::STATUS_HELD, $pending->fresh()->status);
    }

    public function test_already_split_row_cannot_be_split_again(): void
    {
        $pending = $this->multiPartyPending();
        $this->splitter()->split($this->tenant, $pending, $this->invoiceType);

        $this->expectException(RuntimeException::class);
        $this->splitter()->split($this->tenant, $pending->fresh(), $this->invoiceType);
    }
}
