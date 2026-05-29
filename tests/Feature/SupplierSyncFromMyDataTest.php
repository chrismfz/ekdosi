<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Supplier;
use App\Services\MyData\SupplierSyncFromMyData;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\Psr7\Response;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Network-layer test for supplier sync: a Guzzle MockHandler feeds canned
 * RequestDocs XML through firebed. Verifies the issuer-AFM extraction,
 * dedup/upsert on (company_id, afm), the self-AFM skip, name-from-doc vs
 * name-less, pagination, and that an existing supplier is left alone.
 *
 * GSIS enrichment is exercised with --no-enrich (enrich=false) so the test
 * needs no SOAP/GSIS mock — the GR-without-name case lands as name-less,
 * which is the deterministic, network-free path.
 */
class SupplierSyncFromMyDataTest extends TestCase
{
    use RefreshDatabase;

    private Company $tenant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Company::create([
            'name' => 'Sync test',
            'slug' => 'supsync-'.uniqid(),
            'country_code' => 'GR',
            'einvoice_provider' => 'gr-mydata',
            'mydata_mode' => 'sandbox',
            'afm' => '801280908',   // "us" — must never become a supplier
            'mydata_aade_id_sandbox' => 'TESTUSER',
            'mydata_subscription_key_sandbox' => 'TESTKEY',
        ]);
    }

    private function sync(MockHandler $mock, bool $enrich = false)
    {
        return (new SupplierSyncFromMyData($this->tenant, $mock))->sync(
            now()->subMonth(),
            now(),
            $enrich,
        );
    }

    public function test_creates_unique_suppliers_skips_self_and_dedupes(): void
    {
        $result = $this->sync(new MockHandler([
            new Response(200, [], $this->pageOne()),
            new Response(200, [], $this->pageTwo()),
        ]));

        // Both pages consumed → pagination followed the continuationToken.
        $this->assertSame(5, $result->scannedDocs, '5 docs across both pages');

        // Unique AFMs: 998482379 (×2), 802438394, ALPHANET(foreign) — and our
        // own 801280908 is filtered out, so 3 suppliers, not 4.
        $this->assertSame(3, $result->uniqueAfms);
        $this->assertSame(3, $result->created);

        // Never added ourselves.
        $this->assertDatabaseMissing('suppliers', [
            'company_id' => $this->tenant->id,
            'afm' => '801280908',
        ]);

        // The recurring GR AFM exists exactly once (deduped), name-less
        // (enrich off → no GSIS), source=sync.
        $greek = Supplier::where('company_id', $this->tenant->id)->where('afm', '998482379')->get();
        $this->assertCount(1, $greek);
        $this->assertSame('sync', $greek->first()->source->value);
        $this->assertNull($greek->first()->name);

        // The foreign issuer carried a name + address → taken from the doc.
        $foreign = Supplier::where('company_id', $this->tenant->id)->where('afm', 'DE811234567')->first();
        $this->assertNotNull($foreign);
        $this->assertSame('ALPHANET GMBH', $foreign->name);
        $this->assertSame('DE', $foreign->country);
        $this->assertSame('Hauptstrasse 5', $foreign->address1);

        $this->assertSame(1, $result->namedFromDoc);   // the foreign one
        $this->assertSame(2, $result->nameless);        // the two GR ones
    }

    public function test_existing_supplier_is_left_alone(): void
    {
        // Pre-existing manual supplier for a GR AFM that the feed also carries.
        Supplier::create([
            'company_id' => $this->tenant->id,
            'afm' => '998482379',
            'name' => 'Χειροκίνητος Προμηθευτής',
            'source' => 'manual',
        ]);

        $result = $this->sync(new MockHandler([
            new Response(200, [], $this->pageOne()),
            new Response(200, [], $this->pageTwo()),
        ]));

        $this->assertSame(1, $result->skippedExisting);

        // Not overwritten, not duplicated, provenance untouched.
        $rows = Supplier::where('company_id', $this->tenant->id)->where('afm', '998482379')->get();
        $this->assertCount(1, $rows);
        $this->assertSame('Χειροκίνητος Προμηθευτής', $rows->first()->name);
        $this->assertSame('manual', $rows->first()->source->value);
    }

    public function test_empty_window_does_not_crash(): void
    {
        $result = $this->sync(new MockHandler([
            new Response(200, [], <<<'XML'
<?xml version="1.0" encoding="utf-8"?>
<RequestedDoc xmlns="http://www.aade.gr/myDATA/invoice/v1.0">
    <invoicesDoc/>
</RequestedDoc>
XML),
        ]));

        $this->assertSame(0, $result->scannedDocs);
        $this->assertSame(0, $result->created);
        $this->assertSame(0, Supplier::where('company_id', $this->tenant->id)->count());
    }

    /** Page 1: a GR issuer (no name) + a foreign issuer (name+address) + a continuationToken. */
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
            <mark>400012434052701</mark>
            <issuer>
                <vatNumber>998482379</vatNumber>
                <country>GR</country>
                <branch>0</branch>
            </issuer>
            <counterpart>
                <vatNumber>801280908</vatNumber>
                <country>GR</country>
            </counterpart>
            <invoiceHeader><series>A</series><aa>1</aa><issueDate>2026-01-10</issueDate><invoiceType>1.1</invoiceType></invoiceHeader>
            <invoiceSummary><totalGrossValue>124.00</totalGrossValue></invoiceSummary>
        </invoice>
        <invoice>
            <mark>400012434052702</mark>
            <issuer>
                <vatNumber>DE811234567</vatNumber>
                <country>DE</country>
                <name>ALPHANET GMBH</name>
                <address>
                    <street>Hauptstrasse</street>
                    <number>5</number>
                    <postalCode>10115</postalCode>
                    <city>Berlin</city>
                </address>
            </issuer>
            <counterpart><vatNumber>801280908</vatNumber><country>GR</country></counterpart>
            <invoiceHeader><series>B</series><aa>2</aa><issueDate>2026-01-11</issueDate><invoiceType>1.1</invoiceType></invoiceHeader>
            <invoiceSummary><totalGrossValue>500.00</totalGrossValue></invoiceSummary>
        </invoice>
    </invoicesDoc>
</RequestedDoc>
XML;
    }

    /**
     * Page 2: the SAME GR AFM again (dedup), a NEW GR AFM, and a self-billing
     * doc whose issuer is OUR OWN AFM (must be skipped).
     */
    private function pageTwo(): string
    {
        return <<<'XML'
<?xml version="1.0" encoding="utf-8"?>
<RequestedDoc xmlns="http://www.aade.gr/myDATA/invoice/v1.0">
    <invoicesDoc>
        <invoice>
            <mark>400012434052703</mark>
            <issuer><vatNumber>998482379</vatNumber><country>GR</country><branch>0</branch></issuer>
            <counterpart><vatNumber>801280908</vatNumber><country>GR</country></counterpart>
            <invoiceHeader><series>A</series><aa>9</aa><issueDate>2026-01-20</issueDate><invoiceType>1.1</invoiceType></invoiceHeader>
            <invoiceSummary><totalGrossValue>62.00</totalGrossValue></invoiceSummary>
        </invoice>
        <invoice>
            <mark>400012434052704</mark>
            <issuer><vatNumber>802438394</vatNumber><country>GR</country><branch>0</branch></issuer>
            <counterpart><vatNumber>801280908</vatNumber><country>GR</country></counterpart>
            <invoiceHeader><series>C</series><aa>3</aa><issueDate>2026-01-21</issueDate><invoiceType>2.1</invoiceType></invoiceHeader>
            <invoiceSummary><totalGrossValue>248.00</totalGrossValue></invoiceSummary>
        </invoice>
        <invoice>
            <mark>400012434052705</mark>
            <issuer><vatNumber>801280908</vatNumber><country>GR</country><branch>0</branch></issuer>
            <counterpart><vatNumber>801280908</vatNumber><country>GR</country></counterpart>
            <invoiceHeader><series>SELF</series><aa>1</aa><issueDate>2026-01-22</issueDate><invoiceType>9.3</invoiceType></invoiceHeader>
            <invoiceSummary><totalGrossValue>0.00</totalGrossValue></invoiceSummary>
        </invoice>
    </invoicesDoc>
</RequestedDoc>
XML;
    }
}
