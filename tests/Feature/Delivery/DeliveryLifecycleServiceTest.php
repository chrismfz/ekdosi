<?php

namespace Tests\Feature\Delivery;

use App\Models\Company;
use App\Models\Customer;
use App\Models\DeliveryMark;
use App\Models\DeliveryNote;
use App\Models\DeliveryNoteLine;
use App\Models\InvoiceType;
use App\Services\Delivery\DeliveryLifecycleService;
use Firebed\AadeMyData\Enums\DigitalGoodsMovement\DeliveryStatus;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\Psr7\Response as GuzzleResponse;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

/**
 * No-network coverage for DeliveryLifecycleService (D3, Β' φάση). Each lifecycle
 * call is driven through the same Guzzle MockHandler seam as
 * DeliveryNoteSubmitterTest (firebed's MyDataRequest::setHandler), feeding the
 * exact response shapes from firebed's DGM vendor stubs
 * (vendor/.../stubs/digital-goods-movement/*-response.xml):
 *   - register-transfer-response.xml         → <transferMark>222222222222222</…>
 *   - confirm-delivery-outcome-response.xml  → <deliveryOutcomeMark>333…</…>
 *   - cancel-delivery-note-response.xml       (CancelInvoice → <cancellationMark>…)
 *   - request-delivery-note-status-response-*.xml (GetDeliveryNoteStatus)
 *
 * NOTE: like the whole 9.3 / DGM path, NONE of this is sandbox-validated — the
 * shapes are grounded in firebed's reference stubs only.
 */
class DeliveryLifecycleServiceTest extends TestCase
{
    use RefreshDatabase;

    private Company $tenant;

    private InvoiceType $deliveryType;

    private Customer $recipient;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Company::create([
            'name' => 'Delivery lifecycle test',
            'slug' => 'deliv-lc-'.uniqid(),
            'country_code' => 'GR',
            'einvoice_provider' => 'gr-mydata',
            'mydata_mode' => 'sandbox',
            'afm' => '800561849',
            'mydata_aade_id_sandbox' => 'TESTUSER',
            'mydata_subscription_key_sandbox' => 'TESTKEY',
        ]);

        $this->deliveryType = InvoiceType::create([
            'company_id' => $this->tenant->id,
            'code' => 'DA',
            'name' => 'Δελτίο Αποστολής',
            'invcount' => 1,
            'mydata_type' => '9.3',
        ]);

        $this->recipient = Customer::create([
            'company_id' => $this->tenant->id,
            'name' => 'Παραλήπτης ΑΕ',
            'afm' => '123456789',
        ]);
    }

    /** A note that has ALREADY been filed (Α' φάση done): VALID + registered + qrUrl. */
    private function makeFiledNote(array $cacheOverrides = []): DeliveryNote
    {
        $note = DeliveryNote::create([
            'company_id' => $this->tenant->id,
            'invcode' => 'DA1',
            'code' => 1,
            'delivery_type_id' => $this->deliveryType->id,
            'customer_id' => $this->recipient->id,
            'issued_at' => now(),
            'mydata_type' => '9.3',
            'move_purpose' => 8,
            'vehicle_number' => 'ΙΑΒ1234',
            'transport_type' => 2,
            'carrier_afm' => '777777777',
            'loading_street' => 'Φόρτωσης',
            'loading_number' => '10',
            'loading_postcode' => '11111',
            'loading_city' => 'Αθήνα',
            'delivery_street' => 'Παράδοσης',
            'delivery_number' => '20',
            'delivery_postcode' => '22222',
            'delivery_city' => 'Θεσσαλονίκη',
            'recipient_name' => 'Παραλήπτης ΑΕ',
            'recipient_afm' => '123456789',
            'local_status' => 'active',
        ]);

        DeliveryNoteLine::create([
            'company_id' => $this->tenant->id,
            'delivery_note_id' => $note->id,
            'qty' => 3,
            'measurement_unit' => 1,
            'product_descr' => 'Κιβώτια',
        ]);

        // Simulate the submitter's INSERT result (forceFill guarded cache) + audit row.
        $note->forceFill(array_merge([
            'mydata_sent' => true,
            'mydata_state' => 'VALID',
            'mydata_mark' => '480301204040191',
            'mydata_url' => 'https://mydataapidev.aade.gr/TimologioQR/QRInfo?q=testqr',
            'delivery_state' => 'registered',
        ], $cacheOverrides))->save();

        DeliveryMark::create([
            'company_id' => $this->tenant->id,
            'delivery_note_id' => $note->id,
            'mark' => '480301204040191',
            'mydata_action' => 'INSERT',
            'invoice_url' => $note->mydata_url,
            'mark_date' => now()->toDateString(),
            'mark_time' => now()->toTimeString(),
        ]);

        return $note->fresh('lines');
    }

    private function service(string $responseXml): DeliveryLifecycleService
    {
        return $this->serviceWith([$responseXml]);
    }

    /** @param string[] $responseXmls one queued response per upcoming AADE call */
    private function serviceWith(array $responseXmls): DeliveryLifecycleService
    {
        $mock = new MockHandler(array_map(
            fn (string $xml) => new GuzzleResponse(200, [], $xml),
            $responseXmls,
        ));

        return new DeliveryLifecycleService($this->tenant, $mock);
    }

    // ---- registerTransfer ---------------------------------------------

    public function test_register_transfer_moves_to_in_transit_and_stores_mark(): void
    {
        $note = $this->makeFiledNote();

        $mark = $this->service($this->registerTransferResponse())->registerTransfer($note);

        $this->assertInstanceOf(DeliveryMark::class, $mark);
        $this->assertSame('REGISTER_TRANSFER', $mark->mydata_action);
        $this->assertSame('222222222222222', $mark->mark);

        $this->assertDatabaseHas('delivery_marks', [
            'delivery_note_id' => $note->id,
            'mydata_action' => 'REGISTER_TRANSFER',
            'mark' => '222222222222222',
        ]);

        $fresh = $note->fresh();
        $this->assertSame('in_transit', $fresh->delivery_state);
        $this->assertSame('222222222222222', $fresh->transfer_mark);
    }

    public function test_register_transfer_rejects_wrong_state(): void
    {
        // Already in_transit → cannot register again.
        $note = $this->makeFiledNote(['delivery_state' => 'in_transit']);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/Έναρξη διακίνησης/u');

        $this->service($this->registerTransferResponse())->registerTransfer($note);
    }

    public function test_register_transfer_requires_valid_mydata_state(): void
    {
        $note = $this->makeFiledNote(['mydata_state' => null]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/δεν είναι εκδομένο/u');

        $this->service($this->registerTransferResponse())->registerTransfer($note);
    }

    // ---- confirmDelivery ----------------------------------------------

    public function test_confirm_full_delivery_moves_to_delivered(): void
    {
        $note = $this->makeFiledNote(['delivery_state' => 'in_transit']);

        $mark = $this->service($this->confirmOutcomeResponse())->confirmDelivery($note, 'FULL');

        $this->assertSame('CONFIRM_OUTCOME', $mark->mydata_action);
        $this->assertSame('333333333333333', $mark->mark);

        $fresh = $note->fresh();
        $this->assertSame('delivered', $fresh->delivery_state);
        $this->assertSame('333333333333333', $fresh->outcome_mark);
    }

    public function test_confirm_partial_and_none_map_to_partial_and_failed(): void
    {
        $note = $this->makeFiledNote(['delivery_state' => 'in_transit']);
        $this->service($this->confirmOutcomeResponse())->confirmDelivery($note, 'PARTIAL');
        $this->assertSame('partial', $note->fresh()->delivery_state);

        // Reset to in_transit for the NONE leg.
        $note->forceFill(['delivery_state' => 'in_transit'])->save();
        $this->service($this->confirmOutcomeResponse())->confirmDelivery($note, 'NONE');
        $this->assertSame('failed', $note->fresh()->delivery_state);
    }

    public function test_confirm_delivery_rejects_wrong_state(): void
    {
        // Still registered (not in_transit) → cannot confirm.
        $note = $this->makeFiledNote();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/Δήλωση παράδοσης/u');

        $this->service($this->confirmOutcomeResponse())->confirmDelivery($note, 'FULL');
    }

    public function test_confirm_delivery_rejects_unknown_outcome(): void
    {
        $note = $this->makeFiledNote(['delivery_state' => 'in_transit']);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/Άγνωστο αποτέλεσμα/u');

        $this->service($this->confirmOutcomeResponse())->confirmDelivery($note, 'BOGUS');
    }

    // ---- refreshStatus (read-only) ------------------------------------

    public function test_refresh_status_maps_aade_state_and_forcefills(): void
    {
        // Locally still 'registered' but AADE reports IN_TRANSIT → reconcile.
        $note = $this->makeFiledNote();

        $result = $this->service($this->statusResponse('IN_TRANSIT'))->refreshStatus($note);

        $this->assertSame(DeliveryStatus::IN_TRANSIT, $result['aade_status']);
        $this->assertSame('in_transit', $result['mapped_state']);
        $this->assertTrue($result['changed']);
        $this->assertSame('in_transit', $note->fresh()->delivery_state);

        // Read-only: no new delivery_marks row written.
        $this->assertDatabaseMissing('delivery_marks', [
            'delivery_note_id' => $note->id,
            'mydata_action' => 'STATUS',
        ]);
    }

    public function test_refresh_status_no_change_when_already_in_sync(): void
    {
        $note = $this->makeFiledNote(); // registered

        $result = $this->service($this->statusResponse('REGISTERED'))->refreshStatus($note);

        $this->assertSame('registered', $result['mapped_state']);
        $this->assertFalse($result['changed']);
    }

    public function test_refresh_status_requires_mark(): void
    {
        $note = $this->makeFiledNote(['mydata_mark' => null]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/δεν έχει MARK/u');

        $this->service($this->statusResponse('REGISTERED'))->refreshStatus($note);
    }

    // ---- lifecycleHistory (§4.1 timeline) -----------------------------

    public function test_refresh_status_syncs_lifecycle_history(): void
    {
        $note = $this->makeFiledNote();

        $result = $this->service($this->statusResponseWithHistory())->refreshStatus($note);

        // COMPLETED → delivered, and 3 history events captured.
        $this->assertSame('delivered', $note->fresh()->delivery_state);
        $this->assertSame(3, $result['events_synced']);
        $this->assertCount(3, $note->fresh('events')->events);

        // The carrier's RegisterTransfer, with transport details flattened.
        $this->assertDatabaseHas('delivery_note_events', [
            'delivery_note_id' => $note->id,
            'event_type' => 'RegisterTransfer',
            'actor_vat' => '777777777',
            'event_mark' => 222222222222222,
        ]);

        $transfer = $note->events()->where('event_type', 'RegisterTransfer')->first();
        $this->assertSame('AHN0011', $transfer->details['vehicle_number']);
        $this->assertSame('777777777', $transfer->details['carrier_vat']);
        $this->assertSame(2, $transfer->details['transport_type']);   // code stored, NOT the label
        $this->assertArrayNotHasKey('transport_label', $transfer->details);
        $this->assertStringContainsString('Έναρξη διακίνησης', $transfer->typeLabel());
        $this->assertStringContainsString('Όχημα AHN0011', $transfer->summary()); // label rendered live

        // A ConfirmOutcome with PARTIAL outcome.
        $outcome = $note->events()->where('event_type', 'ConfirmOutcome')->first();
        $this->assertSame('PARTIAL', $outcome->details['outcome']);
        $this->assertStringContainsString('Μερική', $outcome->summary());
    }

    public function test_lifecycle_history_sync_is_idempotent(): void
    {
        $note = $this->makeFiledNote();

        // Two identical polls → still 3 rows (updateOrCreate on dedup_key).
        $svc = $this->serviceWith([$this->statusResponseWithHistory(), $this->statusResponseWithHistory()]);
        $svc->refreshStatus($note);
        $svc->refreshStatus($note);

        $this->assertCount(3, $note->fresh('events')->events);
    }

    public function test_refresh_status_without_history_syncs_no_events(): void
    {
        $note = $this->makeFiledNote();

        $result = $this->service($this->statusResponse('IN_TRANSIT'))->refreshStatus($note);

        $this->assertSame(0, $result['events_synced']);
        $this->assertDatabaseMissing('delivery_note_events', ['delivery_note_id' => $note->id]);
    }

    // ---- cancel -------------------------------------------------------

    public function test_cancel_marks_note_cancelled_and_writes_audit(): void
    {
        $note = $this->makeFiledNote();

        $mark = $this->service($this->cancelResponse())->cancel($note, 'λάθος παραλήπτης');

        $this->assertSame('CANCEL', $mark->mydata_action);
        $this->assertSame('480301204040191', $mark->mark); // the cancelled INSERT mark
        $this->assertStringContainsString('λάθος παραλήπτης', (string) $mark->request);

        $this->assertDatabaseHas('delivery_marks', [
            'delivery_note_id' => $note->id,
            'mydata_action' => 'CANCEL',
        ]);

        $fresh = $note->fresh();
        $this->assertSame('CANCELLED', $fresh->mydata_state);
        $this->assertSame('cancelled', $fresh->delivery_state);
        $this->assertSame('cancelled', $fresh->local_status);
    }

    public function test_cancel_refuses_already_cancelled(): void
    {
        $note = $this->makeFiledNote(['mydata_state' => 'CANCELLED', 'delivery_state' => 'cancelled']);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/ήδη ακυρωμένο/u');

        $this->service($this->cancelResponse())->cancel($note);
    }

    public function test_cancel_requires_filed_note(): void
    {
        $note = $this->makeFiledNote(['mydata_mark' => null]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/δεν έχει MARK/u');

        $this->service($this->cancelResponse())->cancel($note);
    }

    public function test_cancel_rejected_by_aade_does_not_mark_cancelled(): void
    {
        $note = $this->makeFiledNote();

        try {
            $this->service($this->cancelRejectedResponse())->cancel($note);
            $this->fail('cancel should throw when myDATA does not return Success');
        } catch (RuntimeException $e) {
            $this->assertMatchesRegularExpression('/απέρριψε/u', $e->getMessage());
        }

        $fresh = $note->fresh();
        $this->assertSame('VALID', $fresh->mydata_state, 'must NOT flip to CANCELLED on a rejected cancel');
        $this->assertNotSame('cancelled', $fresh->delivery_state);
        $this->assertDatabaseMissing('delivery_marks', [
            'delivery_note_id' => $note->id,
            'mydata_action' => 'CANCEL',
        ]);
    }

    // ---- state label --------------------------------------------------

    public function test_state_label_is_greek(): void
    {
        $this->assertSame('Σε διακίνηση', DeliveryLifecycleService::stateLabel('in_transit'));
        $this->assertSame('Παραδόθηκε', DeliveryLifecycleService::stateLabel('delivered'));
        $this->assertNull(DeliveryLifecycleService::stateLabel(null));
    }

    // ---- response stubs (mirror firebed's DGM vendor stubs) -----------

    private function registerTransferResponse(): string
    {
        return <<<'XML'
<?xml version="1.0" encoding="utf-8"?>
<ResponseDoc xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance" xmlns:xsd="http://www.w3.org/2001/XMLSchema">
    <response>
        <transferMark>222222222222222</transferMark>
        <statusCode>Success</statusCode>
    </response>
</ResponseDoc>
XML;
    }

    private function confirmOutcomeResponse(): string
    {
        return <<<'XML'
<?xml version="1.0" encoding="utf-8"?>
<ResponseDoc xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance" xmlns:xsd="http://www.w3.org/2001/XMLSchema">
    <response>
        <deliveryOutcomeMark>333333333333333</deliveryOutcomeMark>
        <statusCode>Success</statusCode>
    </response>
</ResponseDoc>
XML;
    }

    /** CancelInvoice returns a ResponseDoc with cancellationMark + statusCode=Success. */
    private function cancelResponse(): string
    {
        return <<<'XML'
<?xml version="1.0" encoding="utf-8"?>
<ResponseDoc xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance" xmlns:xsd="http://www.w3.org/2001/XMLSchema">
    <response>
        <cancellationMark>400001234599399</cancellationMark>
        <statusCode>Success</statusCode>
    </response>
</ResponseDoc>
XML;
    }

    /** A non-Success cancel response — AADE rejected the cancellation. */
    private function cancelRejectedResponse(): string
    {
        return <<<'XML'
<?xml version="1.0" encoding="utf-8"?>
<ResponseDoc xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance" xmlns:xsd="http://www.w3.org/2001/XMLSchema">
    <response>
        <statusCode>ValidationError</statusCode>
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

    /**
     * Status COMPLETED with the §4.1 lifecycleHistory — mirrors firebed's
     * stub request-delivery-note-status-response-completed.xml (carrier
     * RegisterTransfer + two ConfirmOutcome events).
     */
    private function statusResponseWithHistory(): string
    {
        return <<<'XML'
<?xml version="1.0" encoding="utf-8"?>
<GetDeliveryNoteStatusResponse xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance" xmlns:xsd="http://www.w3.org/2001/XMLSchema">
    <invoiceMark>480301204040191</invoiceMark>
    <status>COMPLETED</status>
    <dispatchTimestamp>2026-02-07T10:00:00Z</dispatchTimestamp>
    <lifecycleHistory>
        <eventType>RegisterTransfer</eventType>
        <eventTimestamp>2026-02-07T10:00:00Z</eventTimestamp>
        <actorVat>777777777</actorVat>
        <mark>222222222222222</mark>
        <transportDetails>
            <vehicleNumber>AHN0011</vehicleNumber>
            <transportType>2</transportType>
            <timeStamp>2026-02-07T10:00:00Z</timeStamp>
            <carrierVatNumber>777777777</carrierVatNumber>
        </transportDetails>
    </lifecycleHistory>
    <lifecycleHistory>
        <eventType>ConfirmOutcome</eventType>
        <eventTimestamp>2026-02-07T11:00:00Z</eventTimestamp>
        <actorVat>777777777</actorVat>
        <mark>333333333333333</mark>
        <outcomeDetails>
            <outcome>PARTIAL</outcome>
            <deliveredWithoutRecipient>false</deliveredWithoutRecipient>
        </outcomeDetails>
    </lifecycleHistory>
    <lifecycleHistory>
        <eventType>ConfirmOutcome</eventType>
        <eventTimestamp>2026-02-07T12:00:00Z</eventTimestamp>
        <actorVat>888888888</actorVat>
        <mark>444444444444444</mark>
        <outcomeDetails>
            <outcome>FULL</outcome>
            <deliveredWithoutRecipient>true</deliveredWithoutRecipient>
        </outcomeDetails>
    </lifecycleHistory>
</GetDeliveryNoteStatusResponse>
XML;
    }
}
