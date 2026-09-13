<?php

namespace Tests\Feature\Delivery;

use App\Models\Company;
use App\Models\InboundDeliveryNote;
use App\Services\Delivery\InboundDeliveryService;
use Firebed\AadeMyData\Enums\DigitalGoodsMovement\DeliveryStatus;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\Psr7\Response;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

/**
 * Slice 4b — the recipient-side actions on a staged «Εισερχόμενα Διακίνησης» row.
 * A Guzzle MockHandler feeds canned AADE responses through firebed. Verifies
 * acknowledge (local-only), reject-by-MARK (stamps state + reject_mark + status),
 * refresh (maps AADE status, flips to cancelled_by_issuer on a terminal cancel,
 * never resurrects a closed row), the AADE-error surface, and tenant isolation.
 */
class InboundDeliveryServiceTest extends TestCase
{
    use RefreshDatabase;

    private Company $tenant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Company::create([
            'name' => 'Inbound svc', 'slug' => 'inbound-svc-'.uniqid(),
            'country_code' => 'GR', 'einvoice_provider' => 'gr-mydata',
            'mydata_mode' => 'sandbox', 'afm' => '801280908',
            'mydata_aade_id_sandbox' => 'TESTUSER', 'mydata_subscription_key_sandbox' => 'TESTKEY',
        ]);
    }

    private function row(array $overrides = []): InboundDeliveryNote
    {
        return InboundDeliveryNote::create(array_merge([
            'company_id' => $this->tenant->id,
            'mydata_mark' => '480301204040191',
            'issuer_afm' => '111111111',
            'issuer_name' => 'ΜΕΤΑΦΟΡΙΚΗ ΑΕ',
            'invoice_type' => '9.3',
            'local_state' => InboundDeliveryNote::STATE_NEW,
            'aade_delivery_status' => DeliveryStatus::REGISTERED->value,
            'payload' => [],
        ], $overrides));
    }

    private function service(?MockHandler $mock = null): InboundDeliveryService
    {
        return new InboundDeliveryService($this->tenant, $mock);
    }

    public function test_acknowledge_is_local_only(): void
    {
        // No MockHandler → proves acknowledge makes NO AADE call.
        $row = $this->service()->acknowledge($this->row());

        $this->assertSame(InboundDeliveryNote::STATE_ACKNOWLEDGED, $row->local_state);
    }

    public function test_acknowledge_only_from_new(): void
    {
        $this->expectException(RuntimeException::class);
        $this->service()->acknowledge($this->row(['local_state' => InboundDeliveryNote::STATE_REJECTED]));
    }

    public function test_reject_by_mark_stamps_state(): void
    {
        $row = $this->service(new MockHandler([
            new Response(200, [], $this->rejectResponse('900000000000009')),
        ]))->reject($this->row(), 'λάθος αποστολή');

        $this->assertSame(InboundDeliveryNote::STATE_REJECTED, $row->local_state);
        $this->assertSame('900000000000009', $row->reject_mark);
        $this->assertSame(DeliveryStatus::REJECTED->value, $row->aade_delivery_status);
    }

    public function test_reject_on_a_terminal_row_throws(): void
    {
        $this->expectException(RuntimeException::class);
        $this->service(new MockHandler([]))
            ->reject($this->row(['local_state' => InboundDeliveryNote::STATE_CONFIRMED]));
    }

    public function test_reject_surfaces_the_aade_error(): void
    {
        $this->expectException(RuntimeException::class);
        $this->service(new MockHandler([
            new Response(200, [], $this->rejectValidationError()),
        ]))->reject($this->row());
    }

    public function test_refresh_maps_the_aade_status(): void
    {
        $row = $this->row(['aade_delivery_status' => DeliveryStatus::REGISTERED->value]);

        $result = $this->service(new MockHandler([
            new Response(200, [], $this->statusResponse('IN_TRANSIT')),
        ]))->refreshStatus($row);

        $this->assertTrue($result['changed']);
        $this->assertSame(DeliveryStatus::IN_TRANSIT, $result['status']);
        $this->assertSame(DeliveryStatus::IN_TRANSIT->value, $row->fresh()->aade_delivery_status);
    }

    public function test_refresh_flips_to_cancelled_by_issuer_on_terminal_cancel(): void
    {
        $row = $this->row(['local_state' => InboundDeliveryNote::STATE_NEW]);

        $this->service(new MockHandler([
            new Response(200, [], $this->statusResponse('CANCELLED')),
        ]))->refreshStatus($row);

        $row->refresh();
        $this->assertSame(InboundDeliveryNote::STATE_CANCELLED_BY_ISSUER, $row->local_state);
        $this->assertSame(DeliveryStatus::CANCELLED->value, $row->aade_delivery_status);
    }

    public function test_refresh_never_resurrects_a_closed_row(): void
    {
        // Already rejected on our side; AADE reports CANCELLED — status refreshes,
        // but our terminal disposition must NOT be overwritten.
        $row = $this->row(['local_state' => InboundDeliveryNote::STATE_REJECTED]);

        $this->service(new MockHandler([
            new Response(200, [], $this->statusResponse('CANCELLED')),
        ]))->refreshStatus($row);

        $row->refresh();
        $this->assertSame(InboundDeliveryNote::STATE_REJECTED, $row->local_state);
        $this->assertSame(DeliveryStatus::CANCELLED->value, $row->aade_delivery_status);
    }

    public function test_actions_assert_the_tenant(): void
    {
        $other = Company::create([
            'name' => 'Other', 'slug' => 'other-'.uniqid(), 'country_code' => 'GR',
            'einvoice_provider' => 'gr-mydata', 'afm' => '997073525',
        ]);
        $foreignRow = InboundDeliveryNote::create([
            'company_id' => $other->id, 'mydata_mark' => '480301204040191',
            'invoice_type' => '9.3', 'local_state' => InboundDeliveryNote::STATE_NEW, 'payload' => [],
        ]);

        $this->expectException(RuntimeException::class);
        $this->service()->acknowledge($foreignRow); // acting tenant != row's company
    }

    // ---- fixtures --------------------------------------------------------

    private function rejectResponse(string $rejectMark): string
    {
        return <<<XML
        <?xml version="1.0" encoding="utf-8"?>
        <ResponseDoc xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance" xmlns:xsd="http://www.w3.org/2001/XMLSchema">
            <response>
                <rejectMark>{$rejectMark}</rejectMark>
                <statusCode>Success</statusCode>
            </response>
        </ResponseDoc>
        XML;
    }

    private function rejectValidationError(): string
    {
        return <<<'XML'
        <?xml version="1.0" encoding="utf-8"?>
        <ResponseDoc xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance" xmlns:xsd="http://www.w3.org/2001/XMLSchema">
            <response>
                <statusCode>ValidationError</statusCode>
                <errors>
                    <error>
                        <message>Cannot reject this delivery note</message>
                        <code>402</code>
                    </error>
                </errors>
            </response>
        </ResponseDoc>
        XML;
    }

    private function statusResponse(string $status): string
    {
        return <<<XML
        <?xml version="1.0" encoding="utf-8"?>
        <GetDeliveryNoteStatusResponse xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance" xmlns:xsd="http://www.w3.org/2001/XMLSchema">
            <invoiceMark>480301204040191</invoiceMark>
            <status>{$status}</status>
        </GetDeliveryNoteStatusResponse>
        XML;
    }
}
