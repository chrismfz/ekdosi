<?php

namespace Tests\Feature\WhmcsInbox;

use App\Models\Company;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\InvoiceLine;
use App\Models\InvoiceType;
use App\Models\PaymentMethod;
use App\Models\PendingWhmcsInvoice;
use App\Models\VatCategory;
use App\Services\WhmcsInbox\WhmcsInvoiceFiler;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use LogicException;
use Tests\TestCase;

/**
 * Stage B-2 — WhmcsInvoiceFiler: builds Invoice + Lines + submits to
 * AADE. Tenant runs in 'off' mode so the NullSubmitter path fires
 * (returns a MyDataMark with action=SKIPPED, no MARK number, no HTTP
 * call) — exercises the orchestration without requiring AADE creds.
 */
class WhmcsInvoiceFilerTest extends TestCase
{
    use RefreshDatabase;

    private Company $tenant;
    private Customer $customer;
    private InvoiceType $invoiceType;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Company::create([
            'name' => 'T',
            'slug' => 'f-'.uniqid(),
            'country_code' => 'GR',
            'einvoice_provider' => 'gr-mydata',
            'mydata_mode' => 'off',   // NullSubmitter path
        ]);
        VatCategory::create([
            'company_id' => $this->tenant->id,
            'name' => '24%',
            'rate' => 24.00,
            'is_default' => true,
        ]);
        $pm = PaymentMethod::create([
            'company_id' => $this->tenant->id,
            'name' => 'Cash',
            'due_days' => 0,
            'is_active' => true,
        ]);
        $this->invoiceType = InvoiceType::create([
            'company_id' => $this->tenant->id,
            'name' => 'ΤΠΥ',
            'code' => 'ΤΠΥ',
            'invcount' => 1,    // first allocation will produce ΤΠΥ1 (numberer reads-then-bumps)
            'payment_method_id' => $pm->id,
        ]);
        $this->customer = Customer::create([
            'company_id' => $this->tenant->id,
            'name' => 'ΑΚΜΕ',
            'afm' => '111111111',
        ]);
    }

    private function makePending(array $items): PendingWhmcsInvoice
    {
        return PendingWhmcsInvoice::create([
            'company_id'       => $this->tenant->id,
            'whmcs_invoice_id' => 8888,
            'payload'          => [
                'invoiceid' => 8888,
                'userid'    => 1,
                'date'      => '2026-05-20',
                'total'     => '124.00',
                'items'     => ['item' => $items],
            ],
            'match_reason'     => PendingWhmcsInvoice::REASON_LINKED,
            'status'           => PendingWhmcsInvoice::STATUS_PENDING_REVIEW,
            'customer_id'      => $this->customer->id,
        ]);
    }

    public function test_file_creates_invoice_with_lines_and_marks_pending_filed(): void
    {
        $pending = $this->makePending([
            ['description' => 'Domain ekdosi.gr 1y', 'amount' => '124.00', 'taxed' => '1'],
        ]);

        $result = app(WhmcsInvoiceFiler::class)->file(
            $this->tenant,
            $pending,
            $this->customer,
            $this->invoiceType,
            filedByUserId: null,
        );

        // Invoice persisted with allocated invcode + lines + totals.
        $this->assertInstanceOf(Invoice::class, $result->invoice);
        $this->assertSame($this->tenant->id, $result->invoice->company_id);
        $this->assertSame($this->customer->id, $result->invoice->customer_id);
        $this->assertSame('ΤΠΥ1', $result->invoice->invcode);
        $this->assertSame(1, InvoiceLine::where('invoice_id', $result->invoice->id)->count());

        // Totals recomputed from persisted lines.
        $this->assertEqualsWithDelta(100.0, (float) $result->invoice->net_total, 0.01);
        $this->assertEqualsWithDelta(124.0, (float) $result->invoice->gross_total, 0.01);

        // Pending row promoted.
        $fresh = $pending->fresh();
        $this->assertSame(PendingWhmcsInvoice::STATUS_FILED, $fresh->status);
        $this->assertNotNull($fresh->filed_at);
        // off-mode → no MARK; mydata_mark stays null.
        $this->assertNull($fresh->mydata_mark);
    }

    public function test_file_bumps_invcount_for_next_invoice_of_same_type(): void
    {
        $a = $this->makePending([['description' => 'A', 'amount' => '124.00', 'taxed' => '1']]);
        $r1 = app(WhmcsInvoiceFiler::class)->file($this->tenant, $a, $this->customer, $this->invoiceType);
        $this->assertSame('ΤΠΥ1', $r1->invoice->invcode);

        // Second pending row for same tenant + type → next ΑΑ.
        $b = PendingWhmcsInvoice::create([
            'company_id'       => $this->tenant->id,
            'whmcs_invoice_id' => 8889,
            'payload'          => ['invoiceid' => 8889, 'userid' => 1, 'date' => '2026-05-21', 'total' => '50.00',
                                   'items' => ['item' => [['description' => 'B', 'amount' => '50.00', 'taxed' => '0']]]],
            'match_reason'     => PendingWhmcsInvoice::REASON_LINKED,
            'status'           => PendingWhmcsInvoice::STATUS_PENDING_REVIEW,
        ]);
        $r2 = app(WhmcsInvoiceFiler::class)->file($this->tenant, $b, $this->customer, $this->invoiceType);
        $this->assertSame('ΤΠΥ2', $r2->invoice->invcode);
    }

    public function test_file_refuses_to_re_file_already_filed_row(): void
    {
        $pending = $this->makePending([['description' => 'X', 'amount' => '124.00', 'taxed' => '1']]);
        app(WhmcsInvoiceFiler::class)->file($this->tenant, $pending, $this->customer, $this->invoiceType);

        // Try to re-file the same row.
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('already filed');
        app(WhmcsInvoiceFiler::class)->file($this->tenant->fresh(), $pending->fresh(), $this->customer, $this->invoiceType);
    }

    public function test_file_logs_would_be_whmcs_writeback_for_stage_b3(): void
    {
        Log::spy();
        $pending = $this->makePending([['description' => 'X', 'amount' => '124.00', 'taxed' => '1']]);
        app(WhmcsInvoiceFiler::class)->file($this->tenant, $pending, $this->customer, $this->invoiceType);

        // The deferred-writeback log line is what Stage B-3 plugin
        // will replace with an actual HTTP call. Locking in the log
        // message so removing it surfaces in CI.
        Log::shouldHaveReceived('info')
            ->once()
            ->withArgs(function ($message, $context) {
                return str_contains($message, 'WHMCS write-back deferred')
                    && $context['whmcs_invoice_id'] === 8888;
            });
    }

    public function test_preview_returns_FilePreview_without_persisting(): void
    {
        $pending = $this->makePending([['description' => 'X', 'amount' => '124.00', 'taxed' => '1']]);

        $preview = app(WhmcsInvoiceFiler::class)->preview(
            $this->tenant, $pending, $this->customer, $this->invoiceType,
        );

        $this->assertSame(124.0, $preview->totals['gross_total']);
        $this->assertCount(1, $preview->lines);
        // No invoice persisted.
        $this->assertSame(0, Invoice::count());
        // Pending row unchanged.
        $this->assertSame(PendingWhmcsInvoice::STATUS_PENDING_REVIEW, $pending->fresh()->status);
    }
}
