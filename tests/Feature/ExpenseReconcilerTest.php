<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Expense;
use App\Models\Supplier;
use App\Services\MyData\AadeDocSummary;
use App\Services\MyData\ExpenseReconciler;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\Psr7\Response;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Network-layer test for the EXPENSES reconciler: a Guzzle MockHandler feeds
 * canned RequestDocs XML through firebed. Verifies pagination, the issuer→
 * counterpart mapping, the empty-window TypeError guard, cancellation folding,
 * and the five diff buckets against local expenses.
 */
class ExpenseReconcilerTest extends TestCase
{
    use RefreshDatabase;

    private Company $tenant;

    protected function setUp(): void
    {
        parent::setUp();

        // The AADE XML fixtures carry fixed Jan-2026 issue dates; freeze "now" into
        // that window so the now-relative reconcile window includes the local
        // expenses AND their dates line up with AADE for the content compare (MYD-017).
        Carbon::setTestNow('2026-01-15 12:00:00');

        $this->tenant = Company::create([
            'name' => 'Exp recon',
            'slug' => 'exprecon-'.uniqid(),
            'country_code' => 'GR',
            'einvoice_provider' => 'gr-mydata',
            'mydata_mode' => 'sandbox',
            'afm' => '801280908',
            'mydata_aade_id_sandbox' => 'TESTUSER',
            'mydata_subscription_key_sandbox' => 'TESTKEY',
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function reconciler(MockHandler $mock): ExpenseReconciler
    {
        return new ExpenseReconciler($this->tenant, $mock);
    }

    public function test_paginates_folds_cancellations_and_maps_issuer(): void
    {
        $result = $this->reconciler(new MockHandler([
            new Response(200, [], $this->pageOne()),
            new Response(200, [], $this->pageTwo()),
        ]))->reconcile(now()->subMonth(), now());

        // 3 unique expense docs across the two pages, no local expenses yet.
        $this->assertSame(3, $result->aadeTotal);
        $this->assertCount(3, $result->missingLocally);

        $byMark = collect($result->missingLocally)->keyBy('mark');

        // Issuer (supplier) name + AFM folded into the counterpart* fields.
        $this->assertSame('ΠΡΟΜΗΘΕΥΤΗΣ ΑΕ', $byMark['400000000000001']->counterpartName);
        $this->assertSame(124.00, $byMark['400000000000001']->gross);
        $this->assertSame('VALID', $byMark['400000000000001']->aadeState);

        // Inline <cancelledByMark> → cancelled.
        $this->assertSame('CANCELLED', $byMark['400000000000002']->aadeState);
        // Listed in <cancelledInvoicesDoc> → folded to cancelled.
        $this->assertSame('CANCELLED', $byMark['400000000000003']->aadeState);

        // missingLocally rows carry no local id.
        $this->assertNull($byMark['400000000000001']->expenseId);
    }

    public function test_blank_or_non_numeric_totals_parse_to_null_not_zero(): void
    {
        // Firebed's typed ?float getter would THROW on a blank/non-numeric total;
        // reading the raw attribute + toFloat() maps it to null (UNVERIFIED) so a
        // broken summary never becomes a real 0.0 (expense-side mirror of sales).
        $xml = <<<'XML'
<?xml version="1.0" encoding="utf-8"?>
<RequestedDoc xmlns="http://www.aade.gr/myDATA/invoice/v1.0">
    <invoicesDoc>
        <invoice>
            <mark>400000000000060</mark>
            <issuer><vatNumber>998482379</vatNumber><country>GR</country><name>ΠΡΟΜΗΘΕΥΤΗΣ</name></issuer>
            <counterpart><vatNumber>801280908</vatNumber><country>GR</country></counterpart>
            <invoiceHeader><series>Β</series><aa>60</aa><issueDate>2026-01-10</issueDate><invoiceType>1.1</invoiceType></invoiceHeader>
            <invoiceSummary><totalNetValue></totalNetValue><totalGrossValue>xyz</totalGrossValue></invoiceSummary>
        </invoice>
    </invoicesDoc>
</RequestedDoc>
XML;

        $result = $this->reconciler(new MockHandler([new Response(200, [], $xml)]))
            ->reconcile(now()->subMonth(), now());

        $row = collect($result->missingLocally)->firstWhere('mark', '400000000000060');
        $this->assertNotNull($row);
        $this->assertNull($row->net, 'blank <totalNetValue/> → null');
        $this->assertNull($row->gross, 'non-numeric <totalGrossValue> → null');
    }

    public function test_empty_window_is_safe(): void
    {
        $result = $this->reconciler(new MockHandler([
            new Response(200, [], <<<'XML'
<?xml version="1.0" encoding="utf-8"?>
<RequestedDoc xmlns="http://www.aade.gr/myDATA/invoice/v1.0">
    <invoicesDoc/>
</RequestedDoc>
XML),
        ]))->reconcile(now()->subMonth(), now());

        $this->assertSame(0, $result->aadeTotal);
        $this->assertFalse($result->hasDiscrepancies());
    }

    public function test_buckets_matched_mismatch_and_missing(): void
    {
        $supplier = Supplier::create([
            'company_id' => $this->tenant->id,
            'afm' => '998482379',
            'name' => 'ΠΡΟΜΗΘΕΥΤΗΣ ΑΕ',
            'source' => 'sync',
        ]);

        // matched: local VALID, AADE VALID (mark 001).
        $this->expense('400000000000001', 'VALID', $supplier->id);
        // stateMismatch: local VALID but AADE cancels it (page two: mark 002).
        $this->expense('400000000000002', 'VALID', $supplier->id);
        // missingAtAade: local holds a MARK the feed never returns.
        $this->expense('400000000000999', 'VALID', $supplier->id);

        $result = $this->reconciler(new MockHandler([
            new Response(200, [], $this->pageOne()),
            new Response(200, [], $this->pageTwo()),
        ]))->reconcile(now()->subMonth(), now());

        $this->assertCount(1, $result->matched);
        $this->assertSame('400000000000001', $result->matched[0]->mark);
        $this->assertNotNull($result->matched[0]->expenseId);
        $this->assertSame('ΠΡΟΜΗΘΕΥΤΗΣ ΑΕ', $result->matched[0]->counterpartName);

        $this->assertCount(1, $result->stateMismatch);
        $this->assertSame('400000000000002', $result->stateMismatch[0]->mark);
        $this->assertSame('CANCELLED', $result->stateMismatch[0]->aadeState);

        $this->assertCount(1, $result->missingAtAade);
        $this->assertSame('400000000000999', $result->missingAtAade[0]->mark);

        // AADE mark 003 has no local expense → actionable "καταχώριση".
        $this->assertCount(1, $result->missingLocally);
        $this->assertSame('400000000000003', $result->missingLocally[0]->mark);

        $this->assertTrue($result->hasDiscrepancies());
    }

    public function test_content_mismatch_when_local_gross_differs_from_aade(): void
    {
        // MYD-017 (expense side): same MARK + state as AADE, but a different local
        // gross → contentMismatch, not a false "matched".
        $supplier = Supplier::create([
            'company_id' => $this->tenant->id, 'afm' => '998482379',
            'name' => 'ΠΡΟΜΗΘΕΥΤΗΣ ΑΕ', 'source' => 'sync',
        ]);
        $this->expense('400000000000001', 'VALID', $supplier->id, ['gross_total' => '999.00']);

        $result = $this->reconciler(new MockHandler([
            new Response(200, [], $this->pageOne()),
            new Response(200, [], $this->pageTwo()),
        ]))->reconcile(now()->subMonth(), now());

        $this->assertCount(0, $result->matched);
        $this->assertCount(1, $result->contentMismatch);
        $this->assertSame('400000000000001', $result->contentMismatch[0]->mark);
        $this->assertStringContainsString('μικτό', $result->contentMismatch[0]->problem);
    }

    public function test_same_gross_but_different_net_is_a_content_mismatch(): void
    {
        // MYD-017 (expense side): gross agrees, but the net/VAT split does not.
        // Proves the expense fold reads <totalNetValue> and the snapshot compares it.
        $supplier = Supplier::create([
            'company_id' => $this->tenant->id, 'afm' => '998482379',
            'name' => 'ΠΡΟΜΗΘΕΥΤΗΣ ΑΕ', 'source' => 'sync',
        ]);
        $this->expense('400000000000001', 'VALID', $supplier->id, ['net_total' => '80.00']);

        $result = $this->reconciler(new MockHandler([
            new Response(200, [], $this->pageOne()),
            new Response(200, [], $this->pageTwo()),
        ]))->reconcile(now()->subMonth(), now());

        $this->assertCount(0, $result->matched);
        $this->assertCount(1, $result->contentMismatch);
        $this->assertStringContainsString('καθαρή αξία', $result->contentMismatch[0]->problem);
        $this->assertStringNotContainsString('μικτό', $result->contentMismatch[0]->problem);
    }

    public function test_content_incomplete_when_local_lacks_a_field_aade_carries(): void
    {
        // MYD-017 review (expense side): same MARK + state, but AADE carries a field
        // (series 'A') the local expense never captured → contentIncomplete, an
        // unverified warning — NOT a false "matched" and NOT a hard conflict.
        $supplier = Supplier::create([
            'company_id' => $this->tenant->id, 'afm' => '998482379',
            'name' => 'ΠΡΟΜΗΘΕΥΤΗΣ ΑΕ', 'source' => 'sync',
        ]);
        $this->expense('400000000000001', 'VALID', $supplier->id, ['series' => null]);

        $result = $this->reconciler(new MockHandler([
            new Response(200, [], $this->pageOne()),
            new Response(200, [], $this->pageTwo()),
        ]))->reconcile(now()->subMonth(), now());

        $this->assertCount(0, $result->matched);
        $this->assertCount(0, $result->contentMismatch);
        $this->assertCount(1, $result->contentIncomplete);
        $this->assertSame('400000000000001', $result->contentIncomplete[0]->mark);
        $this->assertStringContainsString('σειρά', $result->contentIncomplete[0]->problem);
        $this->assertTrue($result->hasDiscrepancies());
    }

    public function test_standalone_cancellation_mark_is_folded_onto_the_row(): void
    {
        // MYD-014: a doc cancelled via the standalone <cancelledInvoicesDoc> list
        // (mark 003, cancellationMark 900000000000003) must carry that cancellation
        // MARK on its row — the evidence SyncExpenseStateFromAade requires.
        $result = $this->reconciler(new MockHandler([
            new Response(200, [], $this->pageOne()),
            new Response(200, [], $this->pageTwo()),
        ]))->reconcile(now()->subMonth(), now());

        $byMark = collect($result->missingLocally)->keyBy('mark');

        $this->assertSame('CANCELLED', $byMark['400000000000003']->aadeState);
        $this->assertSame('900000000000003', $byMark['400000000000003']->cancelledByMark);
        // Inline <cancelledByMark> path still carries its own cancellation MARK.
        $this->assertSame('900000000000002', $byMark['400000000000002']->cancelledByMark);
    }

    /**
     * The unique (company_id, mydata_mark) index makes a local duplicate
     * impossible to WRITE, so we exercise the duplicateLocal branch of the
     * pure diff() directly with two in-memory expenses sharing a MARK — a
     * belt-and-suspenders mirror of the sales reconciler.
     */
    public function test_duplicate_local_via_pure_diff(): void
    {
        // Content mirrors the AADE summary below so the collapsed survivor is a
        // clean match — the test targets the duplicateLocal branch, not content.
        $content = [
            'mydata_mark' => '400000000000007', 'mydata_state' => 'VALID',
            'series' => 'A', 'aa' => '7', 'issue_date' => '2026-01-10',
            'gross_total' => '124.00', 'net_total' => '100.00', 'supplier_afm' => '998482379',
            'invoice_type' => '1.1',
        ];
        $a = (new Expense)->forceFill(['id' => 1] + $content);
        $b = (new Expense)->forceFill(['id' => 2] + $content);

        $aade = [new AadeDocSummary(
            mark: '400000000000007', uid: 'U', cancelled: false, cancelledByMark: null,
            series: 'A', aa: '7', issueDate: '2026-01-10',
            counterpartName: 'ΠΡΟΜΗΘΕΥΤΗΣ ΑΕ', counterpartVat: '998482379', gross: 124.0, net: 100.0,
            invoiceType: '1.1',
        )];

        $result = (new ExpenseReconciler($this->tenant))->diff(
            $aade, collect([$a, $b]), '01/01/2026', '31/01/2026',
        );

        $this->assertCount(2, $result->duplicateLocal);
        $this->assertSame('400000000000007', $result->duplicateLocal[0]->mark);
        // The first of the colliding group still reconciles (→ matched).
        $this->assertCount(1, $result->matched);
    }

    private function expense(string $mark, ?string $state, int $supplierId, array $override = []): Expense
    {
        // Defaults mirror the AADE pageOne mark-001 doc so a matched row has no
        // CONTENT difference (MYD-017); pass $override to force a divergence.
        return Expense::create(array_merge([
            'company_id' => $this->tenant->id,
            'supplier_id' => $supplierId,
            'mydata_mark' => $mark,
            'mydata_state' => $state,
            'issue_date' => '2026-01-10',
            'supplier_afm' => '998482379',
            'net_total' => '100.00',
            'gross_total' => '124.00',
            'series' => 'A',
            'aa' => '1',
            'invoice_type' => '1.1',
            'source' => 'sync',
        ], $override));
    }

    private function pageOne(): string
    {
        return <<<'XML'
<?xml version="1.0" encoding="utf-8"?>
<RequestedDoc xmlns="http://www.aade.gr/myDATA/invoice/v1.0">
    <continuationToken>
        <nextPartitionKey>PK1</nextPartitionKey>
        <nextRowKey>RK1</nextRowKey>
    </continuationToken>
    <invoicesDoc>
        <invoice>
            <uid>UID1</uid>
            <mark>400000000000001</mark>
            <issuer>
                <vatNumber>998482379</vatNumber>
                <country>GR</country>
                <name>ΠΡΟΜΗΘΕΥΤΗΣ ΑΕ</name>
            </issuer>
            <counterpart><vatNumber>801280908</vatNumber><country>GR</country></counterpart>
            <invoiceHeader><series>A</series><aa>1</aa><issueDate>2026-01-10</issueDate><invoiceType>1.1</invoiceType></invoiceHeader>
            <invoiceSummary><totalNetValue>100.00</totalNetValue><totalGrossValue>124.00</totalGrossValue></invoiceSummary>
        </invoice>
    </invoicesDoc>
</RequestedDoc>
XML;
    }

    private function pageTwo(): string
    {
        return <<<'XML'
<?xml version="1.0" encoding="utf-8"?>
<RequestedDoc xmlns="http://www.aade.gr/myDATA/invoice/v1.0">
    <invoicesDoc>
        <invoice>
            <uid>UID2</uid>
            <mark>400000000000002</mark>
            <cancelledByMark>900000000000002</cancelledByMark>
            <issuer><vatNumber>998482379</vatNumber><country>GR</country></issuer>
            <counterpart><vatNumber>801280908</vatNumber><country>GR</country></counterpart>
            <invoiceHeader><series>A</series><aa>2</aa><issueDate>2026-01-11</issueDate><invoiceType>1.1</invoiceType></invoiceHeader>
            <invoiceSummary><totalNetValue>161.29</totalNetValue><totalGrossValue>200.00</totalGrossValue></invoiceSummary>
        </invoice>
        <invoice>
            <uid>UID3</uid>
            <mark>400000000000003</mark>
            <issuer><vatNumber>802438394</vatNumber><country>GR</country></issuer>
            <counterpart><vatNumber>801280908</vatNumber><country>GR</country></counterpart>
            <invoiceHeader><series>B</series><aa>3</aa><issueDate>2026-01-12</issueDate><invoiceType>2.1</invoiceType></invoiceHeader>
            <invoiceSummary><totalNetValue>40.32</totalNetValue><totalGrossValue>50.00</totalGrossValue></invoiceSummary>
        </invoice>
    </invoicesDoc>
    <cancelledInvoicesDoc>
        <cancelledInvoice>
            <invoiceMark>400000000000003</invoiceMark>
            <cancellationMark>900000000000003</cancellationMark>
            <cancellationDate>2026-01-13</cancellationDate>
        </cancelledInvoice>
    </cancelledInvoicesDoc>
</RequestedDoc>
XML;
    }
}
