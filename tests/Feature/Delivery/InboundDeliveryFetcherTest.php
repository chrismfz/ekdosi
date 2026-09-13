<?php

namespace Tests\Feature\Delivery;

use App\Models\Company;
use App\Models\InboundDeliveryNote;
use App\Models\Supplier;
use App\Services\Delivery\InboundDeliveryFetcher;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\Psr7\Response;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Slice 4a — network-layer test for the inbound-movement fetcher: a Guzzle
 * MockHandler feeds canned RequestDocs XML through firebed. Verifies the
 * movement filter (only ψηφιακή-διακίνηση docs staged), field parsing, the
 * idempotent upsert (re-poll never duplicates and never clobbers an operator's
 * disposition), and tenant isolation.
 */
class InboundDeliveryFetcherTest extends TestCase
{
    use RefreshDatabase;

    private Company $tenant;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-09-15 12:00:00');

        $this->tenant = $this->makeTenant('inbound-a');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function makeTenant(string $slugPrefix): Company
    {
        return Company::create([
            'name' => 'Inbound '.$slugPrefix,
            'slug' => $slugPrefix.'-'.uniqid(),
            'country_code' => 'GR',
            'einvoice_provider' => 'gr-mydata',
            'mydata_mode' => 'sandbox',
            'afm' => '801280908',
            'mydata_aade_id_sandbox' => 'TESTUSER',
            'mydata_subscription_key_sandbox' => 'TESTKEY',
        ]);
    }

    private function fetcher(Company $tenant, MockHandler $mock): InboundDeliveryFetcher
    {
        return new InboundDeliveryFetcher($tenant, $mock);
    }

    public function test_stages_only_movement_docs_and_parses_fields(): void
    {
        $result = $this->fetcher($this->tenant, new MockHandler([
            new Response(200, [], $this->page([
                $this->movementDoc('500000000000001', '9.3', status: 3, withLifecycle: true),
                $this->plainInvoiceDoc('500000000000002'),        // NOT a movement → skipped
                $this->combinedTdaDoc('500000000000003'),         // otherDeliveryNoteHeader, no status
            ])),
        ]))->fetch(now()->subMonth(), now());

        $this->assertSame(2, $result->scannedDocs);
        $this->assertSame(2, $result->created);
        $this->assertSame(0, $result->updated);
        $this->assertSame(1, $result->skippedNonMovement);

        $this->assertSame(2, InboundDeliveryNote::where('company_id', $this->tenant->id)->count());
        $this->assertDatabaseMissing('inbound_delivery_notes', ['mydata_mark' => '500000000000002']);

        $ninthree = InboundDeliveryNote::where('company_id', $this->tenant->id)
            ->where('mydata_mark', '500000000000001')->firstOrFail();
        $this->assertSame('111111111', $ninthree->issuer_afm);
        $this->assertSame('ΜΕΤΑΦΟΡΙΚΗ ΑΕ', $ninthree->issuer_name);
        $this->assertSame('9.3', $ninthree->invoice_type);
        $this->assertSame(3, $ninthree->aade_delivery_status);
        $this->assertSame(InboundDeliveryNote::STATE_NEW, $ninthree->local_state);
        $this->assertNotEmpty($ninthree->lifecycle);
        $this->assertSame('RegisterTransfer', $ninthree->lifecycle[0]['type']);

        // The combined ΤΔΑ was kept via the otherDeliveryNoteHeader branch (no status).
        $tda = InboundDeliveryNote::where('company_id', $this->tenant->id)
            ->where('mydata_mark', '500000000000003')->firstOrFail();
        $this->assertSame('1.1', $tda->invoice_type);
        $this->assertNull($tda->aade_delivery_status);
    }

    public function test_matches_an_existing_supplier_by_afm(): void
    {
        $supplier = Supplier::create([
            'company_id' => $this->tenant->id, 'name' => 'ΜΕΤΑΦΟΡΙΚΗ ΑΕ', 'afm' => '111111111',
        ]);

        $this->fetcher($this->tenant, new MockHandler([
            new Response(200, [], $this->page([
                $this->movementDoc('500000000000001', '9.3', status: 3),
            ])),
        ]))->fetch(now()->subMonth(), now());

        $row = InboundDeliveryNote::where('company_id', $this->tenant->id)->firstOrFail();
        $this->assertSame($supplier->id, $row->supplier_id);
    }

    public function test_repoll_is_idempotent_and_preserves_operator_disposition(): void
    {
        // First poll: stage the row.
        $this->fetcher($this->tenant, new MockHandler([
            new Response(200, [], $this->page([
                $this->movementDoc('500000000000001', '9.3', status: 3),
            ])),
        ]))->fetch(now()->subMonth(), now());

        // Operator rejects it + stamps every disposition column the poll must NEVER undo.
        $row = InboundDeliveryNote::where('company_id', $this->tenant->id)->firstOrFail();
        $row->update([
            'local_state' => InboundDeliveryNote::STATE_REJECTED,
            'reject_mark' => '900000000000009',
            'outcome_mark' => '900000000000010',
            'qr_code_url' => 'https://scanned.example/qr',
        ]);

        // Second poll: same MARK, AADE status advanced to 4 (REJECTED).
        $result = $this->fetcher($this->tenant, new MockHandler([
            new Response(200, [], $this->page([
                $this->movementDoc('500000000000001', '9.3', status: 4),
            ])),
        ]))->fetch(now()->subMonth(), now());

        $this->assertSame(0, $result->created);
        $this->assertSame(1, $result->updated);
        $this->assertSame(1, InboundDeliveryNote::where('company_id', $this->tenant->id)->count());

        $row->refresh();
        $this->assertSame(4, $row->aade_delivery_status);                        // AADE snapshot refreshed
        $this->assertSame(InboundDeliveryNote::STATE_REJECTED, $row->local_state); // our disposition kept
        $this->assertSame('900000000000009', $row->reject_mark);                 // our reject MARK kept
        $this->assertSame('900000000000010', $row->outcome_mark);                // our outcome MARK kept
        $this->assertSame('https://scanned.example/qr', $row->qr_code_url);      // our scanned qrUrl kept
    }

    public function test_follows_the_continuation_token_across_pages(): void
    {
        $result = $this->fetcher($this->tenant, new MockHandler([
            // Page 1 carries a continuationToken → the fetcher pulls page 2.
            new Response(200, [], $this->page([
                $this->movementDoc('500000000000001', '9.3', status: 3),
            ], continuationKey: 'PK1')),
            // Page 2 has no token → the loop ends.
            new Response(200, [], $this->page([
                $this->movementDoc('500000000000002', '9.3', status: 3),
            ])),
        ]))->fetch(now()->subMonth(), now());

        $this->assertSame(2, $result->created);
        $this->assertEqualsCanonicalizing(
            ['500000000000001', '500000000000002'],
            InboundDeliveryNote::where('company_id', $this->tenant->id)->pluck('mydata_mark')->all(),
        );
    }

    public function test_repoll_of_a_soft_deleted_row_refreshes_without_duplicating_or_unhiding(): void
    {
        // First poll stages the row; operator "hides" it (soft-delete).
        $this->fetcher($this->tenant, new MockHandler([
            new Response(200, [], $this->page([
                $this->movementDoc('500000000000001', '9.3', status: 3),
            ])),
        ]))->fetch(now()->subMonth(), now());
        InboundDeliveryNote::where('company_id', $this->tenant->id)->firstOrFail()->delete();

        // Re-poll (status advanced): withTrashed lookup refreshes the SAME row —
        // no duplicate, and the operator's hide is respected (stays trashed).
        $result = $this->fetcher($this->tenant, new MockHandler([
            new Response(200, [], $this->page([
                $this->movementDoc('500000000000001', '9.3', status: 8),
            ])),
        ]))->fetch(now()->subMonth(), now());

        $this->assertSame(0, $result->created);
        $this->assertSame(1, $result->updated);
        $this->assertSame(1, InboundDeliveryNote::withTrashed()->where('company_id', $this->tenant->id)->count());

        $row = InboundDeliveryNote::withTrashed()->where('company_id', $this->tenant->id)->firstOrFail();
        $this->assertSame(8, $row->aade_delivery_status);   // snapshot refreshed
        $this->assertTrue($row->trashed());                 // hide respected
    }

    public function test_dry_run_stages_nothing(): void
    {
        $result = $this->fetcher($this->tenant, new MockHandler([
            new Response(200, [], $this->page([
                $this->movementDoc('500000000000001', '9.3', status: 3),
            ])),
        ]))->fetch(now()->subMonth(), now(), dryRun: true);

        $this->assertSame(1, $result->created);       // would-create count
        $this->assertSame(0, InboundDeliveryNote::count());
    }

    public function test_is_tenant_scoped(): void
    {
        $other = $this->makeTenant('inbound-b');

        // Pre-seed the OTHER tenant with the same MARK (unique is per-company).
        InboundDeliveryNote::create([
            'company_id' => $other->id, 'mydata_mark' => '500000000000001',
            'invoice_type' => '9.3', 'local_state' => InboundDeliveryNote::STATE_CONFIRMED,
            'payload' => [],
        ]);

        $this->fetcher($this->tenant, new MockHandler([
            new Response(200, [], $this->page([
                $this->movementDoc('500000000000001', '9.3', status: 3),
            ])),
        ]))->fetch(now()->subMonth(), now());

        // Our tenant got its own fresh row; the other tenant's row is untouched.
        $this->assertSame(InboundDeliveryNote::STATE_NEW, InboundDeliveryNote::where('company_id', $this->tenant->id)
            ->where('mydata_mark', '500000000000001')->value('local_state'));
        $this->assertSame(InboundDeliveryNote::STATE_CONFIRMED, InboundDeliveryNote::where('company_id', $other->id)
            ->where('mydata_mark', '500000000000001')->value('local_state'));
    }

    // ---- fixtures --------------------------------------------------------

    /**
     * @param  list<string>  $docs
     * @param  string|null  $continuationKey  when set, appends a continuationToken so the
     *                                        fetcher paginates to the next MockHandler page
     */
    private function page(array $docs, ?string $continuationKey = null): string
    {
        $body = implode("\n", $docs);
        $token = $continuationKey === null ? '' : <<<XML
            <continuationToken>
                <nextPartitionKey>{$continuationKey}</nextPartitionKey>
                <nextRowKey>{$continuationKey}</nextRowKey>
            </continuationToken>
            XML;

        return <<<XML
        <?xml version="1.0" encoding="utf-8"?>
        <RequestedDoc xmlns:icls="https://www.aade.gr/myDATA/incomeClassificaton/v1.0" xmlns="http://www.aade.gr/myDATA/invoice/v1.0">
            <invoicesDoc>
                {$body}
            </invoicesDoc>
            {$token}
        </RequestedDoc>
        XML;
    }

    private function movementDoc(string $mark, string $type, int $status, bool $withLifecycle = false): string
    {
        $lifecycle = $withLifecycle ? <<<'XML'
            <deliveryLifecycle>
                <deliveryEvents>
                    <eventType>RegisterTransfer</eventType>
                    <eventTimestamp>2026-09-15T09:00:00Z</eventTimestamp>
                    <actorVat>111111111</actorVat>
                    <mark>222222222222222</mark>
                    <transportDetails>
                        <vehicleNumber>ΙΑΒ1234</vehicleNumber>
                        <transportType>2</transportType>
                        <timeStamp>2026-09-15T09:00:00Z</timeStamp>
                    </transportDetails>
                </deliveryEvents>
            </deliveryLifecycle>
            XML : '';

        return <<<XML
        <invoice>
            <mark>{$mark}</mark>
            <issuer>
                <vatNumber>111111111</vatNumber>
                <country>GR</country>
                <branch>0</branch>
                <name>ΜΕΤΑΦΟΡΙΚΗ ΑΕ</name>
            </issuer>
            <invoiceHeader>
                <series>0</series>
                <aa>77</aa>
                <issueDate>2026-09-15</issueDate>
                <invoiceType>{$type}</invoiceType>
            </invoiceHeader>
            <invoiceDeliveryStatus>{$status}</invoiceDeliveryStatus>
            {$lifecycle}
        </invoice>
        XML;
    }

    private function plainInvoiceDoc(string $mark): string
    {
        return <<<XML
        <invoice>
            <mark>{$mark}</mark>
            <issuer>
                <vatNumber>222222222</vatNumber>
                <country>GR</country>
                <branch>0</branch>
                <name>ΠΡΟΜΗΘΕΥΤΗΣ ΑΕ</name>
            </issuer>
            <invoiceHeader>
                <series>0</series>
                <aa>5</aa>
                <issueDate>2026-09-15</issueDate>
                <invoiceType>1.1</invoiceType>
            </invoiceHeader>
        </invoice>
        XML;
    }

    private function combinedTdaDoc(string $mark): string
    {
        return <<<XML
        <invoice>
            <mark>{$mark}</mark>
            <issuer>
                <vatNumber>333333333</vatNumber>
                <country>GR</country>
                <branch>0</branch>
                <name>ΤΔΑ ΑΕ</name>
            </issuer>
            <invoiceHeader>
                <series>0</series>
                <aa>9</aa>
                <issueDate>2026-09-15</issueDate>
                <invoiceType>1.1</invoiceType>
                <otherDeliveryNoteHeader>
                    <loadingAddress>
                        <street>Φόρτωσης</street>
                        <number>10</number>
                        <postalCode>11111</postalCode>
                        <city>Αθήνα</city>
                    </loadingAddress>
                    <deliveryAddress>
                        <street>Παράδοσης</street>
                        <number>20</number>
                        <postalCode>22222</postalCode>
                        <city>Θεσσαλονίκη</city>
                    </deliveryAddress>
                </otherDeliveryNoteHeader>
            </invoiceHeader>
        </invoice>
        XML;
    }
}
