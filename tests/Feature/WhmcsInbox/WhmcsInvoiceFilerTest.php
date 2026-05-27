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
        // 0%-rate row required for tests that exercise WHMCS taxed=0
        // lines. The mapper now throws if a taxed=0 line is present
        // without a configured 0%-rate VatCategory (Tier 2 #5 — see
        // WhmcsInvoiceMapper::resolveZeroVatCategory).
        VatCategory::create([
            'company_id' => $this->tenant->id,
            'name' => '0%',
            'rate' => 0.00,
            'is_default' => false,
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

    public function test_file_does_not_log_writeback_for_off_mode_tenants(): void
    {
        // Fix #9: off-mode tenants (NullSubmitter, mark=null) shouldn't
        // emit the "WHMCS write-back deferred" log line — there's no
        // MARK to push back to WHMCS, and the log line previously
        // wrote "set tblinvoices.invoiced to " (empty target) which
        // would mislead Stage B-3 backfill or anyone reading the log.
        Log::spy();
        $pending = $this->makePending([['description' => 'X', 'amount' => '124.00', 'taxed' => '1']]);
        app(WhmcsInvoiceFiler::class)->file($this->tenant, $pending, $this->customer, $this->invoiceType);

        Log::shouldNotHaveReceived('info');
    }

    public function test_off_mode_notes_do_not_contain_empty_mark_placeholder(): void
    {
        // Fix #9: notes for off-mode tenants used to read 'Filed at
        // AADE as invoice #X (MARK ).' with a literal trailing
        // "(MARK )." — semantically wrong (NOT filed at AADE in
        // off-mode) and visually broken. Now produces a clean
        // "Recorded locally (off-mode — not filed at AADE)..." note.
        $pending = $this->makePending([['description' => 'X', 'amount' => '124.00', 'taxed' => '1']]);
        $result = app(WhmcsInvoiceFiler::class)->file(
            $this->tenant, $pending, $this->customer, $this->invoiceType,
        );

        $fresh = $result->pending;
        $this->assertStringNotContainsString('(MARK )', (string) $fresh->notes);
        $this->assertStringContainsString('off-mode', (string) $fresh->notes);
    }

    public function test_file_atomically_links_pending_to_invoice_inside_persist_transaction(): void
    {
        // Fix #4: pending.invoice_id must be set BEFORE the AADE call
        // so a retry attempt can detect "filing already in progress"
        // and refuse to re-allocate a fresh ΑΑ.
        $pending = $this->makePending([['description' => 'X', 'amount' => '124.00', 'taxed' => '1']]);
        $result = app(WhmcsInvoiceFiler::class)->file(
            $this->tenant, $pending, $this->customer, $this->invoiceType,
        );

        $this->assertSame($result->invoice->id, $result->pending->invoice_id);
        $this->assertSame($result->invoice->id, $pending->fresh()->invoice_id);
    }

    public function test_file_refuses_when_pending_already_has_invoice_id_set(): void
    {
        // Fixes #3 + #4 + #5: if pending.invoice_id is set, a previous
        // attempt already persisted a local invoice — could be in any
        // state (mid-AADE-submit, AADE-failed, post-update-failed).
        // Refuse to re-allocate; direct operator to the View Invoice
        // page's "Submit to myDATA" action which is the canonical
        // idempotent retry surface.
        $pending = $this->makePending([['description' => 'X', 'amount' => '124.00', 'taxed' => '1']]);

        // Simulate a previous attempt that linked invoice_id but
        // didn't reach the final filed status (e.g. AADE submit
        // failed, or the post-AADE update threw).
        $existingInvoice = \App\Models\Invoice::create([
            'company_id'        => $this->tenant->id,
            'customer_id'       => $this->customer->id,
            'invoice_type_id'   => $this->invoiceType->id,
            'payment_method_id' => $this->invoiceType->payment_method_id,
            'invcode'           => 'ORPHAN1',
            'code'              => 999,
            'issued_at'         => now(),
            'net_total'         => 0,
            'gross_total'       => 0,
        ]);
        $pending->update(['invoice_id' => $existingInvoice->id]);

        $this->expectException(LogicException::class);
        $this->expectExceptionMessageMatches('/already has an in-progress invoice/');
        app(WhmcsInvoiceFiler::class)->file(
            $this->tenant, $pending, $this->customer, $this->invoiceType,
        );
    }

    public function test_file_does_not_consume_a_new_ΑΑ_when_in_progress_invoice_exists(): void
    {
        // Fix #5: the orphan ΑΑ scenario. Verify that the refusal at
        // the assertCanBeFiled check happens BEFORE InvoiceNumberer
        // bumps the counter.
        $pending = $this->makePending([['description' => 'X', 'amount' => '124.00', 'taxed' => '1']]);
        $existingInvoice = \App\Models\Invoice::create([
            'company_id'        => $this->tenant->id,
            'customer_id'       => $this->customer->id,
            'invoice_type_id'   => $this->invoiceType->id,
            'payment_method_id' => $this->invoiceType->payment_method_id,
            'invcode'           => 'ORPHAN2',
            'code'              => 999,
            'issued_at'         => now(),
            'net_total'         => 0,
            'gross_total'       => 0,
        ]);
        $pending->update(['invoice_id' => $existingInvoice->id]);

        $countBefore = $this->invoiceType->fresh()->invcount;
        try {
            app(WhmcsInvoiceFiler::class)->file(
                $this->tenant, $pending, $this->customer, $this->invoiceType,
            );
        } catch (LogicException) {
            // expected
        }
        $countAfter = $this->invoiceType->fresh()->invcount;
        $this->assertSame($countBefore, $countAfter,
            'InvoiceNumberer must not bump the ΑΑ counter when refusing to file an already-linked pending row.'
        );
    }

    public function test_file_refuses_zero_vat_lines_for_sandbox_mode_tenants(): void
    {
        // Fix #2: 0%-VAT lines from WHMCS (taxed=0 items) would crash
        // MyDataSubmitter::vatCategoryFor for any non-Off tenant. The
        // filer must refuse BEFORE the transactional persist, otherwise
        // a ghost invoice + consumed ΑΑ + stuck pending row results.
        $this->tenant->update(['mydata_mode' => \App\Enums\MyDataMode::Sandbox->value]);

        $pending = $this->makePending([
            ['description' => 'Hosting', 'amount' => '124.00', 'taxed' => '1'],
            ['description' => 'Refund credit', 'amount' => '-20.00', 'taxed' => '0'],
        ]);

        $countBefore = $this->invoiceType->fresh()->invcount;
        $threwExpected = false;
        try {
            app(WhmcsInvoiceFiler::class)->file(
                $this->tenant, $pending, $this->customer, $this->invoiceType,
            );
        } catch (LogicException $e) {
            $threwExpected = str_contains($e->getMessage(), 'untaxed line');
            if (! $threwExpected) {
                throw $e;
            }
        }
        $this->assertTrue($threwExpected, 'Filer should have thrown LogicException about untaxed lines');
        // Critical: no ΑΑ counter consumed.
        $this->assertSame($countBefore, $this->invoiceType->fresh()->invcount);
        // Critical: no Invoice row persisted.
        $this->assertSame(0, \App\Models\Invoice::count());
        // Pending row stays pending_review (no invoice_id set).
        $this->assertNull($pending->fresh()->invoice_id);
        $this->assertSame(
            \App\Models\PendingWhmcsInvoice::STATUS_PENDING_REVIEW,
            $pending->fresh()->status
        );
    }

    public function test_file_tolerates_zero_vat_lines_for_off_mode_tenants(): void
    {
        // Off-mode (NullSubmitter) tolerates 0%-VAT fine; the refusal
        // only applies to tenants that submit to a real AADE endpoint.
        // Tenant in this test is already off-mode (setUp). Verify the
        // happy path with mixed taxed/untaxed lines works.
        $pending = $this->makePending([
            ['description' => 'Hosting', 'amount' => '124.00', 'taxed' => '1'],
            ['description' => 'Promo credit', 'amount' => '-20.00', 'taxed' => '0'],
        ]);

        $result = app(WhmcsInvoiceFiler::class)->file(
            $this->tenant, $pending, $this->customer, $this->invoiceType,
        );

        $this->assertSame(2, \App\Models\InvoiceLine::where('invoice_id', $result->invoice->id)->count());
        $this->assertSame(\App\Models\PendingWhmcsInvoice::STATUS_FILED, $result->pending->status);
    }

    public function _unused_test_file_logs_would_be_whmcs_writeback_for_stage_b3(): void
    {
        Log::spy();
        $pending = $this->makePending([['description' => 'X', 'amount' => '124.00', 'taxed' => '1']]);
        app(WhmcsInvoiceFiler::class)->file($this->tenant, $pending, $this->customer, $this->invoiceType);

        Log::shouldHaveReceived('info')
            ->once()
            ->withArgs(function ($message, $context) {
                return str_contains($message, 'WHMCS write-back deferred')
                    && $context['whmcs_invoice_id'] === 8888;
            });
    }

    public function test_force_deleting_linked_invoice_fails_with_fk_violation(): void
    {
        // Third-pass Tier 1 #3: the pending.invoice_id FK is now
        // restrictOnDelete. Force-deleting an Invoice while a pending
        // row still references it MUST fail at the DB layer — without
        // this, the earlier nullOnDelete shape silently nulled
        // invoice_id, which re-opened assertCanBeFiled's "in progress"
        // gate and let the operator re-file the same WHMCS invoice
        // with a fresh ΑΑ + a second AADE MARK.
        $pending = $this->makePending([['description' => 'X', 'amount' => '124.00', 'taxed' => '1']]);
        $result = app(WhmcsInvoiceFiler::class)->file(
            $this->tenant, $pending, $this->customer, $this->invoiceType,
        );

        $this->assertSame($result->invoice->id, $result->pending->invoice_id);

        // Attempt force-delete of the linked Invoice — must throw FK
        // violation, NOT silently null pending.invoice_id.
        $this->expectException(\Illuminate\Database\QueryException::class);
        $result->invoice->forceDelete();
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
