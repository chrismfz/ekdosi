<?php

namespace Tests\Feature\Delivery;

use App\Models\Company;
use App\Models\Customer;
use App\Models\DeliveryMark;
use App\Models\DeliveryNote;
use App\Models\DeliveryNoteEvent;
use App\Models\DeliveryNoteLine;
use App\Models\InvoiceType;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\StockMovement;
use App\Models\VatCategory;
use App\Services\Delivery\DeliveryLifecycleService;
use App\Services\Stock\StockService;
use Firebed\AadeMyData\Enums\DigitalGoodsMovement\DeliveryStatus;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\Psr7\Response as GuzzleResponse;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
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
        // Unique invcode/code per call so a test may create several notes (e.g. one
        // per source state) without tripping unique(company_id, invcode).
        static $seq = 0;
        $seq++;

        $note = DeliveryNote::create([
            'company_id' => $this->tenant->id,
            'invcode' => 'DA'.$seq,
            'code' => $seq,
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

    // ---- Combined ΤΔΑ (3a): polymorphic audit mirror -------------------

    public function test_movement_audit_rows_mirror_the_morph_from_delivery_note_id(): void
    {
        // 3a keeps delivery_note_id AND adds movable_*; MirrorsMovableFromDeliveryNote
        // populates the morph on write so a DeliveryNote row is reachable through
        // movable() too — the seam a ΤΔΑ Invoice reuses in 3c. A wrong boot-method name
        // would SILENTLY leave the morph null, so assert it is actually mirrored.
        $note = $this->makeFiledNote(); // creates the INSERT DeliveryMark with delivery_note_id

        $mark = DeliveryMark::where('delivery_note_id', $note->id)->firstOrFail();
        $this->assertSame(DeliveryNote::class, $mark->movable_type);
        $this->assertSame($note->id, (int) $mark->movable_id);
        $this->assertTrue($mark->movable->is($note)); // morphTo resolves back to the note

        $event = DeliveryNoteEvent::create([
            'company_id' => $this->tenant->id,
            'delivery_note_id' => $note->id,
            'event_type' => 'ConfirmOutcome',
            'dedup_key' => 'mirror-k1',
        ])->fresh();
        $this->assertSame(DeliveryNote::class, $event->movable_type);
        $this->assertSame($note->id, (int) $event->movable_id);

        // An explicit morph (a future ΤΔΑ Invoice row) is NEVER overwritten by the hook.
        $explicit = DeliveryMark::create([
            'company_id' => $this->tenant->id,
            'movable_type' => 'App\\Models\\Invoice',
            'movable_id' => 4242,
            'mark' => '999',
            'mydata_action' => 'REGISTER_TRANSFER',
            'mark_date' => now()->toDateString(),
        ])->fresh();
        $this->assertSame('App\\Models\\Invoice', $explicit->movable_type);
        $this->assertSame(4242, (int) $explicit->movable_id);
        $this->assertNull($explicit->delivery_note_id);
    }

    public function test_events_can_be_parented_by_an_invoice_via_the_morph(): void
    {
        // 3c-1: delivery_note_events is fully polymorphic now — a ΤΔΑ Invoice parents
        // its lifecycle events with movable_type=Invoice + delivery_note_id NULL,
        // deduped by the morph unique (movable_type, movable_id, dedup_key), NOT the
        // DeliveryNote FK. This is the seam the generalised service uses in 3c-2.
        $event = DeliveryNoteEvent::create([
            'company_id' => $this->tenant->id,
            'movable_type' => 'App\\Models\\Invoice', 'movable_id' => 555,
            'event_type' => 'ConfirmOutcome', 'dedup_key' => 'inv-k1',
        ])->fresh();
        $this->assertSame('App\\Models\\Invoice', $event->movable_type);
        $this->assertNull($event->delivery_note_id);

        // Idempotent on the morph keys (a re-poll never duplicates an Invoice event).
        DeliveryNoteEvent::updateOrCreate(
            ['movable_type' => 'App\\Models\\Invoice', 'movable_id' => 555, 'dedup_key' => 'inv-k1'],
            ['company_id' => $this->tenant->id, 'event_type' => 'ConfirmOutcome'],
        );
        $this->assertSame(1, DeliveryNoteEvent::query()
            ->where('movable_type', 'App\\Models\\Invoice')->where('movable_id', 555)->count());
    }

    // ---- AADE status → delivery_state mapping (totality) --------------

    public function test_delivery_state_mapping_is_total_over_every_aade_status(): void
    {
        // firebed 5.12 (myDATA v2.0.2) added DeliveryStatus::IN_TRANSIT_RETURN (9).
        // deliveryStateFromAade() must map EVERY enum case (present + future) to
        // string|null WITHOUT throwing UnhandledMatchError — otherwise a status
        // refresh on a return movement crashes. (Method uses no $this state, so a
        // constructor-less instance is enough to exercise the match.)
        $method = new \ReflectionMethod(DeliveryLifecycleService::class, 'deliveryStateFromAade');
        $method->setAccessible(true);
        $service = (new \ReflectionClass(DeliveryLifecycleService::class))->newInstanceWithoutConstructor();

        foreach (DeliveryStatus::cases() as $case) {
            $mapped = $method->invoke($service, $case);
            $this->assertTrue(
                $mapped === null || is_string($mapped),
                "DeliveryStatus::{$case->name} must map to string|null, not throw."
            );
        }

        // Slice 2: IN_TRANSIT_RETURN (9) maps to its OWN state, not 'in_transit'.
        $this->assertSame('in_transit_return', $method->invoke($service, DeliveryStatus::IN_TRANSIT_RETURN));
        $this->assertNull($method->invoke($service, null));
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

    public function test_register_transfer_throws_on_missing_transport_type(): void
    {
        // Non-UI caller (console/API/import) with no transportType: fail locally,
        // never silently omit a mandatory field and let AADE reject it (MYD-013).
        $note = $this->makeFiledNote();
        $note->transport_type = null;
        $note->save();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/τρόπο μεταφοράς/u');

        $this->service($this->registerTransferResponse())->registerTransfer($note->fresh('lines'));
    }

    public function test_register_transfer_throws_on_invalid_transport_type(): void
    {
        $note = $this->makeFiledNote();
        $note->transport_type = 99;   // out of the 1–7 enum
        $note->save();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/τρόπο μεταφοράς/u');

        $this->service($this->registerTransferResponse())->registerTransfer($note->fresh('lines'));
    }

    public function test_register_transfer_requires_vehicle_for_non_without_type(): void
    {
        // transportType 2 (φορτηγό ΙΧ) needs a vehicleNumber; empty → local error.
        $note = $this->makeFiledNote();
        $note->transport_type = 2;
        $note->vehicle_number = null;
        $note->save();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/μεταφορικού μέσου/u');

        $this->service($this->registerTransferResponse())->registerTransfer($note->fresh('lines'));
    }

    public function test_register_transfer_rejects_whitespace_only_vehicle(): void
    {
        // A blank-looking «   » is not a vehicle number (trim() is load-bearing).
        $note = $this->makeFiledNote();
        $note->transport_type = 2;
        $note->vehicle_number = '   ';
        $note->save();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/μεταφορικού μέσου/u');

        $this->service($this->registerTransferResponse())->registerTransfer($note->fresh('lines'));
    }

    public function test_register_transfer_allows_type_7_without_vehicle(): void
    {
        // Type 7 (Άνευ) may carry no vehicle → must NOT throw; a placeholder
        // vehicleNumber is still emitted so AADE gets the required element.
        $note = $this->makeFiledNote();
        $note->transport_type = 7;
        $note->vehicle_number = null;
        $note->save();

        $mark = $this->service($this->registerTransferResponse())->registerTransfer($note->fresh('lines'));

        $this->assertSame('in_transit', $note->fresh()->delivery_state);
        $this->assertStringContainsString('<transportType>7</transportType>', $mark->request);
        $this->assertStringContainsString('<vehicleNumber>', $mark->request);
    }

    public function test_register_transfer_payload_carries_transport_type_and_vehicle(): void
    {
        // A valid type produces the mandatory transportType + vehicleNumber payload.
        $note = $this->makeFiledNote();   // transport_type 2, vehicle ΙΑΒ1234

        $mark = $this->service($this->registerTransferResponse())->registerTransfer($note);

        $this->assertStringContainsString('<transportType>2</transportType>', $mark->request);
        $this->assertStringContainsString('<vehicleNumber>ΙΑΒ1234</vehicleNumber>', $mark->request);
    }

    // NB: no confirmDelivery tests — the method was removed. ConfirmDeliveryOutcome is
    // the recipient's/carrier's call ([833]), never the issuer's, so it is gated out of
    // this issuer-scoped service (docs/delivery-two-party-sandbox.md). The OUTCOME is
    // exercised via refreshStatus mapping (DeliveredByCarrier/Completed/FailedDelivery),
    // covered by the refresh tests below.

    // ---- confirmReturn (δήλωση επιστροφής, v2.0.2) ---------------------

    public function test_confirm_return_completes_and_stores_return_mark(): void
    {
        // 'failed' is a §3.2.7 source (the carrier failed to deliver). 'in_transit'
        // was pruned — AADE rejects it [828] (sandbox rehearsal 2026-09-13).
        $note = $this->makeFiledNote(['delivery_state' => 'failed']);

        $mark = $this->service($this->confirmReturnResponse())->confirmReturn($note);

        $this->assertSame('CONFIRM_RETURN', $mark->mydata_action);
        $this->assertSame('444444444444444', $mark->mark);

        $fresh = $note->fresh();
        $this->assertSame('returned', $fresh->delivery_state);
        $this->assertSame('444444444444444', $fresh->return_mark);
    }

    public function test_confirm_return_rejects_wrong_state(): void
    {
        // Still registered (not in_transit) → cannot declare a return.
        $note = $this->makeFiledNote();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/Δήλωση επιστροφής/u');

        $this->service($this->confirmReturnResponse())->confirmReturn($note);
    }

    public function test_returned_state_survives_a_refresh_reporting_completed(): void
    {
        // A return-completion is NOT a plain delivery: AADE reports it as
        // Completed (→ 'delivered' in the map), but refreshStatus must not
        // downgrade our terminal 'returned' cache.
        $note = $this->makeFiledNote(['delivery_state' => 'returned']);

        $result = $this->service($this->statusResponse('COMPLETED'))->refreshStatus($note);

        $this->assertSame('returned', $note->fresh()->delivery_state);
        $this->assertFalse($result['changed']);
    }

    public function test_confirm_return_is_allowed_from_in_transit_return(): void
    {
        // Slice 2: when the carrier has already started the return leg (AADE
        // reports IN_TRANSIT_RETURN → our 'in_transit_return'), the issuer can
        // still close it with a return declaration.
        $note = $this->makeFiledNote(['delivery_state' => 'in_transit_return']);

        $mark = $this->service($this->confirmReturnResponse())->confirmReturn($note);

        $this->assertSame('CONFIRM_RETURN', $mark->mydata_action);
        $this->assertSame('444444444444444', $note->fresh()->return_mark);
        $this->assertSame('returned', $note->fresh()->delivery_state);
    }

    /**
     * Slice-2 follow-up: DGM v2.0.2 §3.2.7 lists the issuer's ConfirmDeliveryReturn
     * sources as Rejected / DeliveredByCarrier(PARTIAL) / FailedDelivery → our
     * rejected/partial/failed. These were previously blocked (guard was only
     * in_transit/in_transit_return), so an operator could not close a return on a
     * note the lifecycle had left rejected/partial/failed.
     */
    public function test_confirm_return_is_allowed_from_rejected_partial_failed(): void
    {
        foreach (['rejected', 'partial', 'failed'] as $from) {
            $note = $this->makeFiledNote(['delivery_state' => $from]);

            $mark = $this->service($this->confirmReturnResponse())->confirmReturn($note);

            $this->assertSame('CONFIRM_RETURN', $mark->mydata_action, "from {$from}");
            $this->assertSame('returned', $note->fresh()->delivery_state, "from {$from}");
        }
    }

    public function test_confirm_return_still_rejects_states_outside_the_spec_set(): void
    {
        // registered (pre-transit) and delivered (fully completed) are NOT
        // ConfirmDeliveryReturn sources — must still be refused. 'in_transit' was
        // pruned after the sandbox rehearsal proved AADE rejects it [828].
        foreach (['registered', 'in_transit', 'delivered', 'cancelled'] as $from) {
            $note = $this->makeFiledNote(['delivery_state' => $from]);
            try {
                $this->service($this->confirmReturnResponse())->confirmReturn($note);
                $this->fail("confirmReturn should refuse from '{$from}'");
            } catch (RuntimeException $e) {
                $this->assertMatchesRegularExpression('/Δήλωση επιστροφής/u', $e->getMessage());
            }
        }
    }

    // ---- refreshStatus (read-only) ------------------------------------

    public function test_refresh_maps_in_transit_return_to_its_own_state(): void
    {
        // Slice 2: a carrier-reported return leg surfaces as its own state, not
        // collapsed into 'in_transit' — so the operator sees goods are coming back.
        $note = $this->makeFiledNote(['delivery_state' => 'in_transit']);

        $result = $this->service($this->statusResponse('IN_TRANSIT_RETURN'))->refreshStatus($note);

        $this->assertSame(DeliveryStatus::IN_TRANSIT_RETURN, $result['aade_status']);
        $this->assertSame('in_transit_return', $result['mapped_state']);
        $this->assertSame('in_transit_return', $note->fresh()->delivery_state);
        $this->assertTrue($result['changed']);
    }

    public function test_refresh_maps_delivered_by_carrier_partial_to_partial(): void
    {
        // Two-party sandbox truth (2026-09-13): a carrier PARTIAL delivery reports
        // AADE status DeliveredByCarrier — the SAME status as a carrier FULL — and
        // only the ConfirmOutcome lifecycleHistory detail says PARTIAL. It MUST map
        // to 'partial' (not 'delivered') so confirmReturn stays reachable (§3.2.7;
        // AADE accepts the return from here — it posts a deliveryReturnMark).
        $note = $this->makeFiledNote(['delivery_state' => 'in_transit']);

        $result = $this->service($this->statusResponseDeliveredByCarrier('PARTIAL'))->refreshStatus($note);

        $this->assertSame(DeliveryStatus::DELIVERED_BY_CARRIER, $result['aade_status']);
        $this->assertSame('partial', $result['mapped_state']);
        $this->assertSame('partial', $note->fresh()->delivery_state);
        // …and 'partial' is a confirmReturn source, so the operator can close it.
        $this->assertContains('partial', DeliveryLifecycleService::CONFIRM_RETURN_FROM_STATES);
    }

    public function test_refresh_maps_delivered_by_carrier_full_to_delivered(): void
    {
        // A FULL carrier delivery is terminal — no return leg — so DeliveredByCarrier
        // with a FULL ConfirmOutcome stays 'delivered'.
        $note = $this->makeFiledNote(['delivery_state' => 'in_transit']);

        $result = $this->service($this->statusResponseDeliveredByCarrier('FULL'))->refreshStatus($note);

        $this->assertSame(DeliveryStatus::DELIVERED_BY_CARRIER, $result['aade_status']);
        $this->assertSame('delivered', $result['mapped_state']);
        $this->assertSame('delivered', $note->fresh()->delivery_state);
    }

    public function test_delivered_by_carrier_uses_the_latest_outcome_not_any_partial(): void
    {
        // A PARTIAL later SUPERSEDED by a corrective FULL is a full delivery — the
        // mapper must read the LATEST ConfirmOutcome (by timestamp), not the first
        // PARTIAL it finds, else a completed note stays wrongly return-eligible.
        $note = $this->makeFiledNote(['delivery_state' => 'in_transit']);

        $result = $this->service($this->statusResponseDeliveredByCarrierSequence(['PARTIAL', 'FULL']))
            ->refreshStatus($note);

        $this->assertSame('delivered', $result['mapped_state']);
        $this->assertSame('delivered', $note->fresh()->delivery_state);
    }

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

    // ---- MYD-019: remote cancellation via refreshStatus ---------------

    public function test_refresh_status_syncs_a_remote_cancellation_and_returns_stock(): void
    {
        // AADE reports the δελτίο CANCELLED (cancelled outside ekdosi). refreshStatus
        // must sync ALL THREE state fields, write a forensic STATE_SYNC audit row
        // (NOT a CANCEL we initiated), run the STOCK-001 compensation, and flag it.
        $cat = ProductCategory::create(['company_id' => $this->tenant->id, 'description_short' => 'HW', 'markup' => 0]);
        $vat = VatCategory::create(['company_id' => $this->tenant->id, 'description' => '24%', 'rate' => 24, 'is_default' => true]);
        $product = Product::create([
            'company_id' => $this->tenant->id, 'description_short' => 'SSD',
            'product_category_id' => $cat->id, 'vat_category_id' => $vat->id, 'track_stock' => true,
        ]);
        app(StockService::class)->record($product, 10, StockMovement::REASON_INITIAL);

        $note = $this->makeFiledNote();               // VALID / registered / active
        $note->forceFill(['move_purpose' => 1])->save();
        $note->lines()->delete();
        DeliveryNoteLine::create([
            'company_id' => $this->tenant->id, 'delivery_note_id' => $note->id,
            'product_id' => $product->id, 'qty' => 4, 'measurement_unit' => 1,
        ]);
        $note = $note->fresh('lines');
        app(StockService::class)->recordSaleForDeliveryNote($note);   // −4 → 6
        $this->assertSame(6.0, app(StockService::class)->currentStock($product->fresh()));

        $result = $this->service($this->statusResponse('CANCELLED'))->refreshStatus($note);

        $this->assertSame(DeliveryStatus::CANCELLED, $result['aade_status']);
        $this->assertTrue($result['state_synced']);
        $this->assertTrue($result['changed']);

        $fresh = $note->fresh();
        $this->assertSame('CANCELLED', $fresh->mydata_state, 'mydata_state synced');
        $this->assertSame('cancelled', $fresh->local_status, 'local_status synced');
        $this->assertSame('cancelled', $fresh->delivery_state, 'delivery_state synced');

        // Forensic STATE_SYNC row — distinct from a CANCEL we would have initiated.
        $this->assertDatabaseHas('delivery_marks', [
            'delivery_note_id' => $note->id, 'mydata_action' => 'STATE_SYNC',
        ]);
        $this->assertDatabaseMissing('delivery_marks', [
            'delivery_note_id' => $note->id, 'mydata_action' => 'CANCEL',
        ]);

        // STOCK-001 compensation ran — goods returned.
        $this->assertSame(10.0, app(StockService::class)->currentStock($product->fresh()));
    }

    public function test_refresh_status_remote_cancellation_is_idempotent(): void
    {
        $note = $this->makeFiledNote();

        $svc = $this->serviceWith([$this->statusResponse('CANCELLED'), $this->statusResponse('CANCELLED')]);
        $first = $svc->refreshStatus($note);
        $second = $svc->refreshStatus($note->fresh());

        $this->assertTrue($first['state_synced']);
        $this->assertFalse($second['state_synced'], 'already terminal → no-op');
        $this->assertFalse($second['changed']);

        // Exactly ONE STATE_SYNC row across both refreshes.
        $this->assertSame(1, DeliveryMark::query()
            ->where('delivery_note_id', $note->id)
            ->where('mydata_action', 'STATE_SYNC')
            ->count());
    }

    public function test_refresh_status_does_not_resurrect_a_business_cancelled_note(): void
    {
        // Business-cancelled locally (delivery_state already terminal) but the AADE
        // tracking feed still returns a non-terminal status → must NOT be resurrected.
        $note = $this->makeFiledNote(['local_status' => 'cancelled', 'delivery_state' => 'cancelled']);

        $result = $this->service($this->statusResponse('IN_TRANSIT'))->refreshStatus($note);

        $this->assertFalse($result['changed']);
        $this->assertFalse($result['state_synced']);
        $this->assertSame('cancelled', $note->fresh()->delivery_state, 'not flipped back to in_transit');
    }

    public function test_refresh_after_in_app_cancel_writes_no_state_sync_row(): void
    {
        // A δελτίο cancelled IN-APP (real CANCEL row + all three terminal) that later
        // refreshes as CANCELLED must NOT gain a second, STATE_SYNC audit row.
        $note = $this->makeFiledNote();
        $svc = $this->serviceWith([$this->cancelResponse(), $this->statusResponse('CANCELLED')]);

        $svc->cancel($note, 'λάθος παραλήπτης');        // CANCEL row + terminal state
        $result = $svc->refreshStatus($note->fresh());  // AADE agrees: CANCELLED

        $this->assertFalse($result['state_synced'], 'already terminal via CANCEL → no STATE_SYNC');
        $this->assertSame(0, DeliveryMark::query()
            ->where('delivery_note_id', $note->id)->where('mydata_action', 'STATE_SYNC')->count());
        $this->assertSame(1, DeliveryMark::query()
            ->where('delivery_note_id', $note->id)->where('mydata_action', 'CANCEL')->count());
    }

    public function test_refresh_status_heals_a_partially_cancelled_note(): void
    {
        // Split state: local_status + delivery_state already cancelled but mydata_state
        // still VALID → a refresh reporting CANCELLED must COMPLETE the sync (flip
        // mydata_state) and record the STATE_SYNC row, not skip it.
        $note = $this->makeFiledNote(['local_status' => 'cancelled', 'delivery_state' => 'cancelled']);
        $this->assertSame('VALID', $note->mydata_state);

        $result = $this->service($this->statusResponse('CANCELLED'))->refreshStatus($note);

        $this->assertTrue($result['state_synced']);
        $this->assertSame('CANCELLED', $note->fresh()->mydata_state, 'mydata_state healed');
        $this->assertDatabaseHas('delivery_marks', [
            'delivery_note_id' => $note->id, 'mydata_action' => 'STATE_SYNC',
        ]);
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

        // MYD-023: AADE returns its OWN MARK for the cancellation act, and it is
        // separate evidence from the MARK of the document being withdrawn. It was
        // simply discarded — the row recorded only the issue MARK under action
        // CANCEL, so the audit trail could not prove WHICH cancellation event
        // produced the terminal state. (`mydata_marks` has carried both columns
        // since 2026-06-05; `delivery_marks` never did.)
        //
        // This is the path with real production data behind it: myip cancelled a
        // δελτίο on 2026-06-09, and no invoice has ever been cancelled at AADE.
        $this->assertSame('400001234599399', $mark->cancellation_mark, 'the cancel act itself');
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

    public function test_cancel_returns_sold_stock_to_the_ledger(): void
    {
        // STOCK-001: persistCancellation reverses a Πώληση δελτίο's sale-out. Proves
        // the wiring (the ledger logic itself is covered in StockSaleTest).
        $cat = ProductCategory::create(['company_id' => $this->tenant->id, 'description_short' => 'HW', 'markup' => 0]);
        $vat = VatCategory::create(['company_id' => $this->tenant->id, 'description' => '24%', 'rate' => 24, 'is_default' => true]);
        $product = Product::create([
            'company_id' => $this->tenant->id, 'description_short' => 'SSD',
            'product_category_id' => $cat->id, 'vat_category_id' => $vat->id, 'track_stock' => true,
        ]);
        app(StockService::class)->record($product, 10, StockMovement::REASON_INITIAL);

        // makeFiledNote is an ενδοδιακίνηση (move_purpose 8) with a product-less line;
        // turn it into a real Πώληση of the tracked product so a sale-out exists.
        $note = $this->makeFiledNote();
        $note->forceFill(['move_purpose' => 1])->save();
        $note->lines()->delete();
        DeliveryNoteLine::create([
            'company_id' => $this->tenant->id, 'delivery_note_id' => $note->id,
            'product_id' => $product->id, 'qty' => 4, 'measurement_unit' => 1,
        ]);
        $note = $note->fresh('lines');

        app(StockService::class)->recordSaleForDeliveryNote($note);       // −4 → 6
        $this->assertSame(6.0, app(StockService::class)->currentStock($product->fresh()));

        $this->service($this->cancelResponse())->cancel($note, 'επιστροφή');

        $this->assertSame(10.0, app(StockService::class)->currentStock($product->fresh()), 'goods returned on cancel');
        $this->assertDatabaseHas('stock_movements', [
            'product_id' => $product->id,
            'reason' => StockMovement::REASON_CANCEL,
            'source_type' => DeliveryNoteLine::class,
        ]);
        $this->assertSame('cancelled', $note->fresh()->delivery_state);
    }

    /**
     * A cancellation MARK of '' must be stored as NULL, not as ''. The audit row
     * is built with `array_filter(…, fn ($v) => $v !== null)`, which strips only
     * nulls — so an empty MARK would persist and read as «we hold a cancellation
     * MARK», the exact confusion the column split exists to remove.
     */
    public function test_an_empty_cancellation_mark_is_stored_as_no_evidence(): void
    {
        $note = $this->makeFiledNote();

        $mark = $this->service($this->cancelResponseWithoutMark())->cancel($note, '');

        $this->assertSame('480301204040191', $mark->mark, 'still records WHAT was cancelled');
        $this->assertNull($mark->cancellation_mark);
        $this->assertSame('CANCELLED', $note->fresh()->mydata_state, 'the cancellation still stands');
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

    // ---- provider channel: cancel routes via the provider -------------

    public function test_provider_tenant_cancel_routes_via_provider(): void
    {
        // InvoSign exposes iNVOSign_CancelDeliveryNote, so a provider tenant's
        // ΔΑ cancel must go through the SAME channel as its issuance — not direct
        // myDATA. (Έναρξη/παράδοση/έλεγχος stay direct: the provider has no such
        // endpoints; that path is the unchanged firebed one.)
        Http::fake(['*iNVOSign_CancelDeliveryNote*' => Http::response($this->providerCancelXml(), 200)]);

        $provider = Company::create([
            'name' => 'Provider tenant', 'slug' => 'prov-'.uniqid(), 'country_code' => 'GR',
            'einvoice_provider' => 'gr-provider', 'einvoice_provider_key' => 'invosign',
            'einvoice_provider_mode' => 'sandbox', 'afm' => '800561849',
            'einvoice_provider_config' => ['demo_base_url' => 'https://demo.invosign.test', 'demo_token' => 'DEMO-TOKEN'],
        ]);
        $type = InvoiceType::create([
            'company_id' => $provider->id, 'code' => 'DA', 'name' => 'ΔΑ', 'invcount' => 1, 'mydata_type' => '9.3',
        ]);
        $note = DeliveryNote::create([
            'company_id' => $provider->id, 'invcode' => 'DA1', 'code' => 1,
            'delivery_type_id' => $type->id, 'issued_at' => now(), 'mydata_type' => '9.3', 'local_status' => 'active',
        ]);
        $note->forceFill([
            'mydata_sent' => true, 'mydata_state' => 'VALID',
            'mydata_mark' => '400001964635819', 'delivery_state' => 'registered',
        ])->save();
        // Provider issuance writes PROVIDER_INSERT (not INSERT) — cancel must find it.
        DeliveryMark::create([
            'company_id' => $provider->id, 'delivery_note_id' => $note->id,
            'mark' => '400001964635819', 'mydata_action' => 'PROVIDER_INSERT', 'provider_key' => 'invosign',
            'mark_date' => now()->toDateString(), 'mark_time' => now()->toTimeString(),
        ]);

        $audit = (new DeliveryLifecycleService($provider))->cancel($note, 'λάθος παραλήπτης');

        $this->assertSame('CANCEL', $audit->mydata_action);
        $this->assertSame('invosign', $audit->provider_key);

        // MYD-023: the two MARKs are DIFFERENT evidence and live in different
        // columns. This used to assert `mark === cancellationMark`, i.e. it
        // encoded the bug: `$result->cancellationMark ?? $markToCancel` overwrote
        // the document's own MARK with the cancellation one — and fell back to the
        // ISSUE mark when the provider returned none, so the row then claimed the
        // issue MARK was the proof of cancellation.
        $this->assertSame('400001964635819', $audit->mark, 'the document that was cancelled');
        $this->assertSame('400001957363715', $audit->cancellation_mark, "AADE's MARK for the cancel act");

        $fresh = $note->fresh();
        $this->assertSame('CANCELLED', $fresh->mydata_state);
        $this->assertSame('cancelled', $fresh->delivery_state);
        $this->assertSame('cancelled', $fresh->local_status);

        Http::assertSent(fn ($r) => str_contains($r->url(), 'iNVOSign_CancelDeliveryNote')
            && $r['mark'] === '400001964635819');
    }

    private function providerCancelXml(): string
    {
        return <<<'XML'
<?xml version="1.0" encoding="utf-8"?>
<ResponseDoc xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance" xmlns:xsd="http://www.w3.org/2001/XMLSchema">
    <response>
        <cancellationMark>400001957363715</cancellationMark>
        <statusCode>Success</statusCode>
    </response>
</ResponseDoc>
XML;
    }

    // ---- state label --------------------------------------------------

    public function test_state_label_is_greek(): void
    {
        $this->assertSame('Σε διακίνηση', DeliveryLifecycleService::stateLabel('in_transit'));
        $this->assertSame('Σε διακίνηση (επιστροφή)', DeliveryLifecycleService::stateLabel('in_transit_return'));
        $this->assertSame('Παραδόθηκε', DeliveryLifecycleService::stateLabel('delivered'));
        $this->assertNull(DeliveryLifecycleService::stateLabel(null));
    }

    // ---- v2.0.2 return event types (A3) -------------------------------

    public function test_return_event_types_render_label_and_summary(): void
    {
        // RegisterTransferReturn carries the SAME transportDetails as RegisterTransfer
        // → renders the transport summary and the firebed Greek label.
        $registerReturn = new DeliveryNoteEvent([
            'event_type' => 'RegisterTransferReturn',
            'details' => ['vehicle_number' => 'ABC1234', 'transport_type' => 2, 'carrier_vat' => '777777777'],
        ]);
        $this->assertSame('Επιστροφή διακίνησης', $registerReturn->typeLabel());
        $this->assertStringContainsString('Όχημα ABC1234', $registerReturn->summary());

        // ConfirmReturn carries no detail block → its type label says it all.
        $confirmReturn = new DeliveryNoteEvent(['event_type' => 'ConfirmReturn', 'details' => null]);
        $this->assertSame('Επιβεβαίωση επιστροφής', $confirmReturn->typeLabel());
        $this->assertSame('', $confirmReturn->summary());
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

    /** ConfirmDeliveryReturn returns a ResponseDoc with deliveryReturnMark + Success (v2.0.2). */
    private function confirmReturnResponse(): string
    {
        return <<<'XML'
<?xml version="1.0" encoding="utf-8"?>
<ResponseDoc xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance" xmlns:xsd="http://www.w3.org/2001/XMLSchema">
    <response>
        <deliveryReturnMark>444444444444444</deliveryReturnMark>
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

    /** Success, but AADE named no cancellation MARK — '' is not evidence. */
    private function cancelResponseWithoutMark(): string
    {
        return <<<'XML'
<?xml version="1.0" encoding="utf-8"?>
<ResponseDoc xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance" xmlns:xsd="http://www.w3.org/2001/XMLSchema">
    <response>
        <cancellationMark></cancellationMark>
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
     * Status DELIVERED_BY_CARRIER carrying a single ConfirmOutcome event with the
     * given outcome (FULL|PARTIAL) — the status alone is identical for both, so the
     * mapper reads this detail to split 'delivered' vs 'partial'.
     */
    private function statusResponseDeliveredByCarrier(string $outcome): string
    {
        return <<<XML
<?xml version="1.0" encoding="utf-8"?>
<GetDeliveryNoteStatusResponse xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance" xmlns:xsd="http://www.w3.org/2001/XMLSchema">
    <invoiceMark>480301204040191</invoiceMark>
    <status>DELIVERED_BY_CARRIER</status>
    <lifecycleHistory>
        <eventType>RegisterTransfer</eventType>
        <eventTimestamp>2026-09-13T10:00:00Z</eventTimestamp>
        <actorVat>801280908</actorVat>
        <mark>222222222222222</mark>
    </lifecycleHistory>
    <lifecycleHistory>
        <eventType>ConfirmOutcome</eventType>
        <eventTimestamp>2026-09-13T11:00:00Z</eventTimestamp>
        <actorVat>801280908</actorVat>
        <mark>333333333333333</mark>
        <outcomeDetails>
            <outcome>{$outcome}</outcome>
        </outcomeDetails>
    </lifecycleHistory>
</GetDeliveryNoteStatusResponse>
XML;
    }

    /**
     * DELIVERED_BY_CARRIER with several ConfirmOutcome events at increasing
     * timestamps (2026-09-13T10:00, 11:00, …) — to test that the LATEST outcome wins.
     *
     * @param  string[]  $outcomes  in chronological order
     */
    private function statusResponseDeliveredByCarrierSequence(array $outcomes): string
    {
        $events = '';
        foreach (array_values($outcomes) as $i => $outcome) {
            $hour = str_pad((string) (10 + $i), 2, '0', STR_PAD_LEFT);
            $events .= <<<XML

    <lifecycleHistory>
        <eventType>ConfirmOutcome</eventType>
        <eventTimestamp>2026-09-13T{$hour}:00:00Z</eventTimestamp>
        <actorVat>801280908</actorVat>
        <mark>33333333333330{$i}</mark>
        <outcomeDetails>
            <outcome>{$outcome}</outcome>
        </outcomeDetails>
    </lifecycleHistory>
XML;
        }

        return <<<XML
<?xml version="1.0" encoding="utf-8"?>
<GetDeliveryNoteStatusResponse xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance" xmlns:xsd="http://www.w3.org/2001/XMLSchema">
    <invoiceMark>480301204040191</invoiceMark>
    <status>DELIVERED_BY_CARRIER</status>{$events}
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
