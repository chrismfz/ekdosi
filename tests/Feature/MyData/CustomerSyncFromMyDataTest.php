<?php

namespace Tests\Feature\MyData;

use App\Filament\Support\PartySyncWindow;
use App\Models\Company;
use App\Models\Customer;
use App\Services\MyData\CustomerSyncFromMyData;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\Psr7\Response;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Network-layer test for customer sync: a Guzzle MockHandler feeds canned
 * RequestTransmittedDocs XML through firebed, so we verify the counterpart-AFM
 * discovery, dedupe, self-skip and retail-skip without touching AADE.
 *
 * GSIS enrichment is exercised with enrich=false so the test needs no SOAP mock —
 * the GR-without-name case lands as name-less (mirrors SupplierSyncFromMyDataTest).
 */
class CustomerSyncFromMyDataTest extends TestCase
{
    use RefreshDatabase;

    private Company $tenant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Company::create([
            'name' => 'Cust Sync', 'slug' => 'custsync-'.uniqid(),
            'country_code' => 'GR', 'einvoice_provider' => 'gr-mydata', 'mydata_mode' => 'sandbox',
            'afm' => '801280908', // "us" — must never become a customer
            'mydata_aade_id_sandbox' => 'TESTUSER', 'mydata_subscription_key_sandbox' => 'TESTKEY',
        ]);
    }

    private function sync(MockHandler $mock, bool $enrich = false)
    {
        return (new CustomerSyncFromMyData($this->tenant, $mock))->sync(
            now()->subYear(),
            now(),
            $enrich,
        );
    }

    public function test_creates_customers_from_counterparts_dedupes_skips_self_and_retail(): void
    {
        $result = $this->sync(new MockHandler([
            new Response(200, [], $this->page()),
        ]));

        // 123456789 (named, foreign) + 987654321 (GR, no name → nameless).
        // The retail invoice (no counterpart) and the self-AFM are skipped.
        $this->assertSame(2, $result->created);
        $this->assertSame(1, $result->namedFromDoc);
        $this->assertSame(1, $result->nameless);

        $this->assertDatabaseHas('customers', [
            'company_id' => $this->tenant->id, 'afm' => '123456789', 'name' => 'Foreign Buyer Ltd',
        ]);
        // GR no-name + enrich off → «ΑΦΜ …» placeholder (customers.name NOT NULL).
        $this->assertDatabaseHas('customers', [
            'company_id' => $this->tenant->id, 'afm' => '987654321', 'name' => 'ΑΦΜ 987654321',
        ]);
        $this->assertDatabaseMissing('customers', [
            'company_id' => $this->tenant->id, 'afm' => '801280908', // ourselves
        ]);
        $this->assertSame(2, Customer::where('company_id', $this->tenant->id)->count());
    }

    public function test_existing_customer_is_left_alone(): void
    {
        Customer::create(['company_id' => $this->tenant->id, 'name' => 'Ήδη εδώ', 'afm' => '123456789']);

        $result = $this->sync(new MockHandler([
            new Response(200, [], $this->page()),
        ]));

        // 123456789 already on file → skipped; only 987654321 is new.
        $this->assertSame(1, $result->created);
        $this->assertSame(1, $result->skippedExisting);
        $this->assertSame('Ήδη εδώ', Customer::where('afm', '123456789')->value('name')); // untouched
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

        $this->assertSame(0, $result->created);
        $this->assertSame(0, $result->uniqueAfms);
    }

    public function test_window_presets_resolve_to_the_expected_lookback(): void
    {
        [$from3] = PartySyncWindow::resolve(['window' => '3']);
        [$from12] = PartySyncWindow::resolve(['window' => '12']);
        [$from24] = PartySyncWindow::resolve(['window' => '24']);
        [$fromDefault] = PartySyncWindow::resolve([]); // no key → 12

        $this->assertEqualsWithDelta(3, $from3->diffInMonths(now()), 0.1);
        $this->assertEqualsWithDelta(12, $from12->diffInMonths(now()), 0.1);
        $this->assertEqualsWithDelta(24, $from24->diffInMonths(now()), 0.1);
        $this->assertEqualsWithDelta(12, $fromDefault->diffInMonths(now()), 0.1);
    }

    /** One page: a foreign named B2B, a GR no-name B2B, a self-AFM, and a retail (no counterpart). */
    private function page(): string
    {
        return <<<'XML'
<?xml version="1.0" encoding="utf-8"?>
<RequestedDoc xmlns="http://www.aade.gr/myDATA/invoice/v1.0">
    <invoicesDoc>
        <invoice>
            <mark>400000000000001</mark>
            <counterpart><vatNumber>123456789</vatNumber><name>Foreign Buyer Ltd</name></counterpart>
            <invoiceHeader><series>TPY</series><aa>1</aa><issueDate>2026-01-10</issueDate></invoiceHeader>
            <invoiceSummary><totalGrossValue>124.00</totalGrossValue></invoiceSummary>
        </invoice>
        <invoice>
            <mark>400000000000002</mark>
            <counterpart><vatNumber>987654321</vatNumber></counterpart>
            <invoiceHeader><series>TPY</series><aa>2</aa><issueDate>2026-01-11</issueDate></invoiceHeader>
            <invoiceSummary><totalGrossValue>62.00</totalGrossValue></invoiceSummary>
        </invoice>
        <invoice>
            <mark>400000000000003</mark>
            <counterpart><vatNumber>801280908</vatNumber></counterpart>
            <invoiceHeader><series>TPY</series><aa>3</aa><issueDate>2026-01-12</issueDate></invoiceHeader>
            <invoiceSummary><totalGrossValue>10.00</totalGrossValue></invoiceSummary>
        </invoice>
        <invoice>
            <mark>400000000000004</mark>
            <invoiceHeader><series>APY</series><aa>4</aa><issueDate>2026-01-13</issueDate></invoiceHeader>
            <invoiceSummary><totalGrossValue>5.00</totalGrossValue></invoiceSummary>
        </invoice>
    </invoicesDoc>
</RequestedDoc>
XML;
    }
}
