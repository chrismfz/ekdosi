<?php

namespace Tests\Feature\Delivery;

use App\Console\Commands\DeliveryRefreshStatus;
use App\Models\Company;
use App\Models\Customer;
use App\Models\DeliveryMark;
use App\Models\DeliveryNote;
use App\Models\DeliveryNoteEvent;
use App\Models\DeliveryNoteLine;
use App\Models\Invoice;
use App\Models\InvoiceType;
use App\Models\MyDataMark;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\StockMovement;
use App\Models\VatCategory;
use App\Services\Delivery\DeliveryLifecycleService;
use App\Services\EInvoice\MovementHeaderBuilder;
use App\Services\Stock\StockService;
use Firebed\AadeMyData\Enums\DigitalGoodsMovement\DeliveryOutcomeType;
use Firebed\AadeMyData\Enums\DigitalGoodsMovement\DeliveryStatus;
use Firebed\AadeMyData\Models\InvoiceHeader;
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

    /**
     * A Combined ΤΔΑ (Slice 3c): a MONETARY 1.1 invoice with `is_delivery_note=true`
     * that has ALREADY been filed (VALID + registered + qrUrl + MARK), so it can drive
     * the SAME movement lifecycle as a 9.x note through the MovableDocument contract.
     * Uses the SAME issue MARK the status stubs echo, so refreshStatus matches.
     */
    private function makeFiledTda(array $cacheOverrides = []): Invoice
    {
        static $seq = 0;
        $seq++;

        $type = InvoiceType::firstOrCreate(
            ['company_id' => $this->tenant->id, 'code' => 'ΤΔΑ'],
            ['name' => 'Τιμολόγιο–Δελτίο Αποστολής', 'invcount' => 1, 'mydata_type' => '1.1', 'is_delivery_note' => true],
        );

        $invoice = Invoice::create([
            'company_id' => $this->tenant->id,
            'invcode' => 'ΤΔΑ'.$seq,
            'code' => $seq,
            'invoice_type_id' => $type->id,
            'customer_id' => $this->recipient->id,
            'issued_at' => now(),
            'company_name' => 'Παραλήπτης ΑΕ',
            'vat_no' => '123456789',
            'is_delivery_note' => true,
            'move_purpose' => 8,
            'vehicle_number' => 'ΙΑΒ1234',
            'transport_type' => 2,
            'carrier_afm' => '777777777',
            'loading_street' => 'Φόρτωσης', 'loading_number' => '10',
            'loading_postcode' => '11111', 'loading_city' => 'Αθήνα',
            'delivery_street' => 'Παράδοσης', 'delivery_number' => '20',
            'delivery_postcode' => '22222', 'delivery_city' => 'Θεσσαλονίκη',
            'local_status' => 'active',
        ]);

        $invoice->lines()->create([
            'company_id' => $this->tenant->id,
            'product_descr' => 'Κιβώτια',
            'qty' => 3, 'price_per_item' => 100, 'vat_percent' => 24,
        ]);

        // Simulate the 1.1 INSERT result (guarded cache) — the same shape the movement
        // columns take after a real filing, plus the monetary INSERT MyDataMark.
        $invoice->forceFill(array_merge([
            'mydata_sent' => true,
            'mydata_state' => 'VALID',
            'mydata_mark' => '480301204040191',
            'mydata_url' => 'https://mydataapidev.aade.gr/TimologioQR/QRInfo?q=testqr',
            'delivery_state' => 'registered',
        ], $cacheOverrides))->save();

        MyDataMark::create([
            'company_id' => $this->tenant->id,
            'invoice_id' => $invoice->id,
            'mark' => '480301204040191',
            'mydata_action' => 'INSERT',
            'mark_date' => now()->toDateString(),
            'mark_time' => now()->toTimeString(),
        ]);

        return $invoice->fresh('lines');
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

        // Bidirectional (3c): a mark created THROUGH the morphMany relation sets
        // movable_* only — the hook back-fills delivery_note_id so the FK readers
        // (DeliveryNoteSubmitter, FiledSeriesBackfill) still see it.
        $viaRelation = $note->marks()->create([
            'company_id' => $this->tenant->id, 'mark' => '888',
            'mydata_action' => 'REGISTER_TRANSFER', 'mark_date' => now()->toDateString(),
        ])->fresh();
        $this->assertSame($note->id, (int) $viaRelation->delivery_note_id);
        $this->assertSame(DeliveryNote::class, $viaRelation->movable_type);
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

    // ---- Combined ΤΔΑ (3c-2): a 1.1 Invoice drives the lifecycle -------

    public function test_tda_invoice_register_transfer_moves_to_in_transit_via_the_contract(): void
    {
        // The whole point of 3c: DeliveryLifecycleService is typed against
        // MovableDocument, so a MONETARY ΤΔΑ invoice drives RegisterTransfer exactly
        // like a 9.x note — and its audit MARK lands on the polymorphic relation with
        // movable_type=Invoice and delivery_note_id NULL (never a bogus FK).
        $invoice = $this->makeFiledTda();

        $mark = $this->service($this->registerTransferResponse())->registerTransfer($invoice);

        $this->assertSame('in_transit', $invoice->fresh()->delivery_state);
        $this->assertSame('222222222222222', $invoice->fresh()->transfer_mark);

        $this->assertSame(Invoice::class, $mark->movable_type);
        $this->assertSame($invoice->id, (int) $mark->movable_id);
        $this->assertNull($mark->delivery_note_id); // an invoice can't carry the DN FK
        $this->assertSame('REGISTER_TRANSFER', $mark->mydata_action);
    }

    public function test_tda_invoice_confirm_return_stores_the_return_mark_on_its_morph(): void
    {
        $invoice = $this->makeFiledTda(['delivery_state' => 'failed']);

        $mark = $this->service($this->confirmReturnResponse())->confirmReturn($invoice);

        $this->assertSame('returned', $invoice->fresh()->delivery_state);
        $this->assertSame('444444444444444', $invoice->fresh()->return_mark);
        $this->assertSame(Invoice::class, $mark->movable_type);
        $this->assertNull($mark->delivery_note_id);
    }

    public function test_tda_invoice_refresh_syncs_lifecycle_events_under_its_morph(): void
    {
        // refreshStatus → syncLifecycleHistory writes the carrier/recipient timeline
        // through the polymorphic relation, so the events dedup on the Invoice morph
        // (movable_type, movable_id, dedup_key), not a NULL delivery_note_id.
        $invoice = $this->makeFiledTda(['delivery_state' => 'in_transit']);

        $result = $this->service($this->statusResponseWithHistory())->refreshStatus($invoice);

        $this->assertGreaterThan(0, $result['events_synced']);

        $events = DeliveryNoteEvent::query()
            ->where('movable_type', Invoice::class)
            ->where('movable_id', $invoice->id)
            ->get();
        $this->assertGreaterThan(0, $events->count());
        $this->assertTrue($events->every(fn ($e) => $e->delivery_note_id === null));
    }

    public function test_tda_invoice_remote_cancel_routes_through_the_monetary_choke_point(): void
    {
        // §7: a ΤΔΑ is ONE 1.1 document/MARK, so a portal-side cancel discovered via
        // refreshStatus must be applied by the MONETARY choke-point
        // (SyncInvoiceStateFromAade) — a MyDataMark STATE_SYNC row on the invoice,
        // NOT a DeliveryMark STATE_SYNC row — and all three state fields go terminal,
        // including delivery_state which the money-only sync doesn't touch.
        $invoice = $this->makeFiledTda(['delivery_state' => 'in_transit']);

        $result = $this->service($this->statusResponse('CANCELLED'))->refreshStatus($invoice);

        $this->assertTrue($result['state_synced']);

        $fresh = $invoice->fresh();
        $this->assertSame('CANCELLED', $fresh->mydata_state);
        $this->assertSame('cancelled', $fresh->local_status);
        $this->assertSame('cancelled', $fresh->delivery_state);

        // The sync row is on the invoice's OWN audit table (mydata_marks), and NO
        // DeliveryMark STATE_SYNC row was written for the invoice morph.
        $this->assertSame(1, MyDataMark::query()
            ->where('invoice_id', $invoice->id)
            ->where('mydata_action', 'STATE_SYNC')
            ->count());
        $this->assertSame(0, DeliveryMark::query()
            ->where('movable_type', Invoice::class)
            ->where('movable_id', $invoice->id)
            ->where('mydata_action', 'STATE_SYNC')
            ->count());
    }

    public function test_tda_invoice_remote_cancel_is_idempotent_across_refreshes(): void
    {
        // A repeated refresh on an already-terminal ΤΔΑ must not write a second
        // STATE_SYNC row nor re-report a change (the monetary choke-point + the
        // delivery_state guard are both idempotent).
        $invoice = $this->makeFiledTda(['delivery_state' => 'in_transit']);

        $this->service($this->statusResponse('CANCELLED'))->refreshStatus($invoice);
        $second = $this->service($this->statusResponse('CANCELLED'))->refreshStatus($invoice->fresh());

        $this->assertFalse($second['state_synced']);
        $this->assertSame(1, MyDataMark::query()
            ->where('invoice_id', $invoice->id)
            ->where('mydata_action', 'STATE_SYNC')
            ->count());
    }

    public function test_lifecycle_cancel_still_refuses_an_invoice_at_the_type_boundary(): void
    {
        // §7: the movement cancel() stays DeliveryNote-typed — a ΤΔΑ cancels through
        // the monetary path, so passing an Invoice is a hard TypeError, not a silent
        // wrong-path cancel.
        $invoice = $this->makeFiledTda();

        $this->expectException(\TypeError::class);
        // @phpstan-ignore-next-line — intentionally passing the wrong type to prove the guard.
        $this->service($this->cancelResponse())->cancel($invoice, 'λάθος');
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

    public function test_own_vehicle_full_outcome_awaits_an_obligated_recipient(): void
    {
        // «Ίδια μέσα»: WE started the movement (transfer_mark) → we are the carrier and may
        // declare the outcome. B2B (obligated) recipient → «αναμένεται ο παραλήπτης».
        $note = $this->makeFiledNote(['delivery_state' => 'in_transit', 'transfer_mark' => '222222222222222', 'carrier_afm' => null]);

        $mark = $this->service($this->confirmOutcomeResponse())->confirmOutcome($note, DeliveryOutcomeType::FULL);

        $this->assertSame('CONFIRM_OUTCOME', $mark->mydata_action);
        $this->assertSame('555555555555555', $mark->mark);
        $fresh = $note->fresh();
        $this->assertSame('awaiting_recipient', $fresh->delivery_state);
        $this->assertSame('555555555555555', $fresh->outcome_mark);
    }

    public function test_own_vehicle_full_outcome_closes_for_a_non_obligated_recipient(): void
    {
        $note = $this->makeFiledNote(['delivery_state' => 'in_transit', 'transfer_mark' => '222222222222222', 'carrier_afm' => null]);
        $note->forceFill(['non_obligated_recipient' => true])->save();

        $this->service($this->confirmOutcomeResponse())->confirmOutcome($note, DeliveryOutcomeType::FULL);

        $this->assertSame('delivered', $note->fresh()->delivery_state);        // AADE Completed
    }

    public function test_own_vehicle_none_outcome_is_a_failed_delivery(): void
    {
        $note = $this->makeFiledNote(['delivery_state' => 'in_transit', 'transfer_mark' => '222222222222222', 'carrier_afm' => null]);

        $this->service($this->confirmOutcomeResponse())->confirmOutcome($note, DeliveryOutcomeType::NONE);

        $this->assertSame('failed', $note->fresh()->delivery_state);           // → «Δήλωση επιστροφής» available
    }

    public function test_the_post_declaration_refresh_takes_aades_state(): void
    {
        // Flag OFF (we guess «αναμένεται ο παραλήπτης»), but AADE completed it (e.g. a
        // private recipient) → the read-only refresh right after settles to «Παραδόθηκε».
        $note = $this->makeFiledNote(['delivery_state' => 'in_transit', 'transfer_mark' => '222222222222222', 'carrier_afm' => null]);

        $this->serviceWith([$this->confirmOutcomeResponse(), $this->statusResponse('COMPLETED')])
            ->confirmOutcome($note, DeliveryOutcomeType::FULL);

        $this->assertSame('delivered', $note->fresh()->delivery_state);
    }

    public function test_own_vehicle_outcome_on_a_combined_tda_invoice(): void
    {
        $tda = $this->makeFiledTda(['delivery_state' => 'in_transit', 'transfer_mark' => '222222222222222', 'carrier_afm' => null]);

        $mark = $this->service($this->confirmOutcomeResponse())->confirmOutcome($tda, DeliveryOutcomeType::FULL);

        $this->assertSame('CONFIRM_OUTCOME', $mark->mydata_action);
        $fresh = $tda->fresh();
        $this->assertSame('awaiting_recipient', $fresh->delivery_state);
        $this->assertSame('555555555555555', $fresh->outcome_mark);       // invoices.outcome_mark
        $this->assertSame(1, $fresh->movementMarks()->where('mydata_action', 'CONFIRM_OUTCOME')->count());
    }

    public function test_outcome_is_refused_when_a_courier_was_declared_as_carrier(): void
    {
        // We started it, but with a courier's ΑΦΜ as carrier → not «ίδια μέσα».
        $note = $this->makeFiledNote(['delivery_state' => 'in_transit', 'transfer_mark' => '222222222222222']);
        $note->forceFill(['carrier_afm' => '099999999'])->save();

        $this->assertFalse(DeliveryLifecycleService::isOwnVehicleCarrier($note->fresh(), $this->tenant));
        $this->expectException(RuntimeException::class);
        $this->service($this->confirmOutcomeResponse())->confirmOutcome($note->fresh(), DeliveryOutcomeType::FULL);
    }

    public function test_the_scheduled_refresh_rereads_only_open_movements(): void
    {
        // An «αναμένεται ο παραλήπτης» note the recipient has since scanned → AADE COMPLETED.
        $open = $this->makeFiledNote(['delivery_state' => 'awaiting_recipient']);
        // Terminal / our-own-next-step states are NOT polled (no mock response queued for them).
        $this->makeFiledNote(['delivery_state' => 'delivered']);
        $this->makeFiledNote(['delivery_state' => 'failed']);

        DeliveryRefreshStatus::$testHandler = new MockHandler([
            new GuzzleResponse(200, [], $this->statusResponse('COMPLETED')),
        ]);
        try {
            $this->artisan('delivery:refresh-status', ['--tenant' => $this->tenant->slug])
                ->expectsOutputToContain('ελέγχθηκαν 1, άλλαξαν 1')
                ->assertSuccessful();
        } finally {
            DeliveryRefreshStatus::$testHandler = null;
        }

        $this->assertSame('delivered', $open->fresh()->delivery_state);
    }

    public function test_the_scheduled_refresh_rotates_through_documents_beyond_the_cap(): void
    {
        // Two open notes, cap 1: a no-change poll must NOT re-pick the same note next run
        // (updated_at doesn't move on a no-change refresh — movement_checked_at does).
        $a = $this->makeFiledNote(['delivery_state' => 'in_transit']);
        $b = $this->makeFiledNote(['delivery_state' => 'in_transit']);

        foreach ([1, 2] as $_) {
            DeliveryRefreshStatus::$testHandler = new MockHandler([
                new GuzzleResponse(200, [], $this->statusResponse('IN_TRANSIT')),
            ]);
            try {
                $this->artisan('delivery:refresh-status', ['--tenant' => $this->tenant->slug, '--limit' => 1])->assertSuccessful();
            } finally {
                DeliveryRefreshStatus::$testHandler = null;
            }
        }

        $this->assertNotNull($a->fresh()->movement_checked_at);
        $this->assertNotNull($b->fresh()->movement_checked_at);                 // both polled across 2 runs
    }

    public function test_outcome_is_refused_when_someone_else_started_the_movement(): void
    {
        // in_transit WITHOUT our transfer_mark = a third-party carrier registered it → not ours to declare.
        $note = $this->makeFiledNote(['delivery_state' => 'in_transit', 'transfer_mark' => null]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('δεν μεταφέρεται από εμάς');
        $this->service($this->confirmOutcomeResponse())->confirmOutcome($note, DeliveryOutcomeType::FULL);
    }

    public function test_outcome_requires_in_transit_and_rejects_partial(): void
    {
        $registered = $this->makeFiledNote(['delivery_state' => 'registered', 'transfer_mark' => '222222222222222', 'carrier_afm' => null]);
        try {
            $this->service($this->confirmOutcomeResponse())->confirmOutcome($registered, DeliveryOutcomeType::FULL);
            $this->fail('registered must be refused');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('Δήλωση παράδοσης', $e->getMessage());
        }

        $moving = $this->makeFiledNote(['delivery_state' => 'in_transit', 'transfer_mark' => '222222222222222', 'carrier_afm' => null]);
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('deliveredPackaging');
        $this->service($this->confirmOutcomeResponse())->confirmOutcome($moving, DeliveryOutcomeType::PARTIAL);
    }

    public function test_the_non_obligated_flag_reaches_the_movement_header_but_never_with_untracked(): void
    {
        // The shared builder (9.3 AND ΤΔΑ) emits nonObligatedRecipient — except on an
        // untracked ΤΔΑ, where AADE [290] forbids the combination.
        $note = new DeliveryNote(['non_obligated_recipient' => true]);
        $h = new InvoiceHeader;
        MovementHeaderBuilder::applyCommon($h, 1, $note);
        $this->assertTrue($h->get('nonObligatedRecipient'));

        $tda = new Invoice(['non_obligated_recipient' => true, 'without_digital_transport_tracking' => true]);
        $h2 = new InvoiceHeader;
        MovementHeaderBuilder::applyCommon($h2, 1, $tda);
        $this->assertNull($h2->get('nonObligatedRecipient'));

        $plain = new DeliveryNote(['non_obligated_recipient' => false]);
        $h3 = new InvoiceHeader;
        MovementHeaderBuilder::applyCommon($h3, 1, $plain);
        $this->assertNull($h3->get('nonObligatedRecipient'));
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

    public function test_refresh_maps_delivered_by_carrier_full_to_awaiting_recipient(): void
    {
        // A FULL carrier delivery has no return leg, but an OBLIGATED recipient still has
        // to confirm (QR scan) before AADE moves it to Completed → «αναμένεται ο
        // παραλήπτης», not yet «Παραδόθηκε» (sandbox 2026-09-25).
        $note = $this->makeFiledNote(['delivery_state' => 'in_transit']);

        $result = $this->service($this->statusResponseDeliveredByCarrier('FULL'))->refreshStatus($note);

        $this->assertSame(DeliveryStatus::DELIVERED_BY_CARRIER, $result['aade_status']);
        $this->assertSame('awaiting_recipient', $result['mapped_state']);
        $this->assertSame('awaiting_recipient', $note->fresh()->delivery_state);
    }

    public function test_delivered_by_carrier_uses_the_latest_outcome_not_any_partial(): void
    {
        // A PARTIAL later SUPERSEDED by a corrective FULL is a full delivery — the
        // mapper must read the LATEST ConfirmOutcome (by timestamp), not the first
        // PARTIAL it finds, else a completed note stays wrongly return-eligible.
        $note = $this->makeFiledNote(['delivery_state' => 'in_transit']);

        $result = $this->service($this->statusResponseDeliveredByCarrierSequence(['PARTIAL', 'FULL']))
            ->refreshStatus($note);

        $this->assertSame('awaiting_recipient', $result['mapped_state']);     // FULL wins, not return-eligible
        $this->assertSame('awaiting_recipient', $note->fresh()->delivery_state);
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

    /** ConfirmDeliveryOutcome returns a ResponseDoc with deliveryOutcomeMark + Success. */
    private function confirmOutcomeResponse(): string
    {
        return <<<'XML'
<?xml version="1.0" encoding="utf-8"?>
<ResponseDoc xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance" xmlns:xsd="http://www.w3.org/2001/XMLSchema">
    <response>
        <deliveryOutcomeMark>555555555555555</deliveryOutcomeMark>
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
