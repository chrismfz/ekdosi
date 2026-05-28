<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Services\MyData\SalesReconciler;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\Psr7\Response;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Network-layer test for the reconciler: a Guzzle MockHandler feeds
 * canned RequestTransmittedDocs XML through firebed so we verify
 * pagination (continuationToken) and the cancellation-folding (inline
 * <cancelledByMark> AND the standalone <cancelledInvoicesDoc> list).
 *
 * No local invoices exist, so every AADE doc lands in `missingLocally`
 * — convenient because each row carries the parsed aadeState we want
 * to assert.
 */
class SalesReconcilerFetchTest extends TestCase
{
    use RefreshDatabase;

    private Company $tenant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Company::create([
            'name' => 'Fetch test',
            'slug' => 'fetch-'.uniqid(),
            'country_code' => 'GR',
            'einvoice_provider' => 'gr-mydata',
            'mydata_mode' => 'sandbox',
            'afm' => '800561849',
            'mydata_aade_id' => 'TESTUSER',
            'mydata_subscription_key' => 'TESTKEY',
        ]);
    }

    public function test_paginates_and_folds_cancellations(): void
    {
        $mock = new MockHandler([
            new Response(200, [], $this->pageOne()),
            new Response(200, [], $this->pageTwo()),
        ]);

        $result = (new SalesReconciler($this->tenant, $mock))->reconcile(
            now()->subMonth(),
            now(),
        );

        // Both pages consumed → pagination followed the continuationToken.
        $this->assertSame(0, $mock->count(), 'Both paginated responses should be consumed');

        // 3 unique invoices across the two pages.
        $this->assertSame(3, $result->aadeTotal);
        $this->assertCount(3, $result->missingLocally);

        $byMark = collect($result->missingLocally)->keyBy('mark');

        $this->assertSame('VALID', $byMark['400000000000001']->aadeState);
        // Inline <cancelledByMark> → cancelled
        $this->assertSame('CANCELLED', $byMark['400000000000002']->aadeState);
        // Listed in <cancelledInvoicesDoc> → folded to cancelled
        $this->assertSame('CANCELLED', $byMark['400000000000003']->aadeState);

        // Display fields parsed off the header/summary/counterpart.
        $this->assertSame('Πελάτης Α', $byMark['400000000000001']->counterpartName);
        $this->assertSame(124.00, $byMark['400000000000001']->gross);
    }

    public function test_empty_window_response_does_not_crash(): void
    {
        // AADE returns an empty <invoicesDoc/> container when nothing
        // matches the window — firebed parses that to a scalar, so an
        // unguarded foreach would TypeError. Must yield an empty result.
        $mock = new MockHandler([
            new Response(200, [], <<<'XML'
<?xml version="1.0" encoding="utf-8"?>
<RequestedDoc xmlns="http://www.aade.gr/myDATA/invoice/v1.0">
    <invoicesDoc/>
</RequestedDoc>
XML),
        ]);

        $result = (new SalesReconciler($this->tenant, $mock))->reconcile(
            now()->subMonth(),
            now(),
        );

        $this->assertSame(0, $result->aadeTotal);
        $this->assertSame(0, $mock->count());
        $this->assertFalse($result->hasDiscrepancies());
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
            <counterpart>
                <vatNumber>123456789</vatNumber>
                <name>Πελάτης Α</name>
            </counterpart>
            <invoiceHeader>
                <series>TPY</series>
                <aa>1</aa>
                <issueDate>2026-01-10</issueDate>
            </invoiceHeader>
            <invoiceSummary>
                <totalGrossValue>124.00</totalGrossValue>
            </invoiceSummary>
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
            <invoiceHeader>
                <series>TPY</series>
                <aa>2</aa>
                <issueDate>2026-01-11</issueDate>
            </invoiceHeader>
            <invoiceSummary>
                <totalGrossValue>200.00</totalGrossValue>
            </invoiceSummary>
        </invoice>
        <invoice>
            <uid>UID3</uid>
            <mark>400000000000003</mark>
            <invoiceHeader>
                <series>TPY</series>
                <aa>3</aa>
                <issueDate>2026-01-12</issueDate>
            </invoiceHeader>
            <invoiceSummary>
                <totalGrossValue>50.00</totalGrossValue>
            </invoiceSummary>
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
