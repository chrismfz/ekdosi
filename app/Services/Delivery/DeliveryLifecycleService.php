<?php

namespace App\Services\Delivery;

use App\Contracts\MovableDocument;
use App\Enums\MyDataMode;
use App\Models\Company;
use App\Models\DeliveryMark;
use App\Models\DeliveryNote;
use App\Models\Invoice;
use App\Services\EInvoice\ProviderTransportRegistry;
use App\Services\MyData\SyncInvoiceStateFromAade;
use App\Support\EInvoice\ProviderCredentials;
use App\Support\MyData\CancellationMark;
use Firebed\AadeMyData\Enums\DigitalGoodsMovement\DeliveryEventType;
use Firebed\AadeMyData\Enums\DigitalGoodsMovement\DeliveryOutcomeType;
use Firebed\AadeMyData\Enums\DigitalGoodsMovement\DeliveryStatus;
use Firebed\AadeMyData\Enums\DigitalGoodsMovement\TransportType;
use Firebed\AadeMyData\Exceptions\MyDataAuthenticationException;
use Firebed\AadeMyData\Exceptions\MyDataConnectionException;
use Firebed\AadeMyData\Exceptions\MyDataException;
use Firebed\AadeMyData\Exceptions\MyDataTimeoutException;
use Firebed\AadeMyData\Http\CancelInvoice;
use Firebed\AadeMyData\Http\DigitalGoodsMovement\ConfirmDeliveryOutcome;
use Firebed\AadeMyData\Http\DigitalGoodsMovement\ConfirmDeliveryReturn;
use Firebed\AadeMyData\Http\DigitalGoodsMovement\RegisterTransfer;
use Firebed\AadeMyData\Http\DigitalGoodsMovement\RequestDeliveryNoteStatus;
use Firebed\AadeMyData\Http\MyDataRequest;
use Firebed\AadeMyData\Models\DigitalGoodsMovement\DeliveryEvent;
use Firebed\AadeMyData\Models\DigitalGoodsMovement\DeliveryNoteStatusResponse;
use Firebed\AadeMyData\Models\DigitalGoodsMovement\DeliveryOutcome;
use Firebed\AadeMyData\Models\DigitalGoodsMovement\DeliveryReturn;
use Firebed\AadeMyData\Models\DigitalGoodsMovement\Response as DgmResponse;
use Firebed\AadeMyData\Models\DigitalGoodsMovement\ResponseDoc;
use Firebed\AadeMyData\Models\DigitalGoodsMovement\Transport;
use Firebed\AadeMyData\Models\DigitalGoodsMovement\TransportDetails;
use GuzzleHttp\Handler\MockHandler;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

/**
 * Β' φάση — the e-transport (Ψηφιακό Δελτίο Αποστολής) LIFECYCLE for an
 * ALREADY-FILED delivery note. Twin of DeliveryNoteSubmitter (the issue / Α'
 * φάση): same per-tenant initFirebed seam, same MockHandler test hook, same
 * try/catch → Greek RuntimeException mapping, same persist-in-a-transaction
 * style (forceFill the note's GUARDED lifecycle columns + write a delivery_marks
 * audit row). The submitter is NEVER touched — this rides ON TOP of its INSERT.
 *
 * The lifecycle KEY is the qrUrl (`mydata_url`), NOT the issue MARK: AADE's
 * RegisterTransfer / ConfirmDeliveryOutcome / Reject identify the δελτίο by the
 * QR url printed on the document (the carrier scans it). Only refreshStatus()
 * and cancel() key off the issue MARK (`mydata_mark`).
 *
 * The `delivery_state` machine (a denormalised cache of the AADE §8.22 status,
 * written ONLY here via forceFill alongside the audit row):
 *
 *     registered ──registerTransfer──▶ in_transit
 *     in_transit ──confirmOutcome (ONLY if WE registered the transfer = we are the carrier, «ίδια μέσα»)──▶
 *                  delivered (nonObligatedRecipient → AADE Completed) | awaiting_recipient (B2B → DeliveredByCarrier) | failed (NONE)
 *     in_transit ──(AADE reports Completed/DeliveredByCarrier/FailedDelivery via refresh)──▶ delivered | awaiting_recipient | partial | failed
 *                  (a third-party carrier / the recipient files the outcome; we OBSERVE it. DeliveredByCarrier PARTIAL
 *                   vs FULL is split by the ConfirmOutcome lifecycleHistory detail — PARTIAL keeps confirmReturn reachable,
 *                   FULL = awaiting_recipient until the recipient's QR scan moves AADE to Completed → delivered.)
 *     {rejected|partial|failed|in_transit_return} ──confirmReturn──▶ returned  (v2.0.2 §3.2.7; AADE→Completed, deliveryReturnMark; `in_transit` pruned — AADE [828], see CONFIRM_RETURN_FROM_STATES)
 *     in_transit ──(AADE reports IN_TRANSIT_RETURN via refresh)──▶ in_transit_return  (carrier-side return leg; we don't submit it)
 *     (any filed) ──cancel──▶ cancelled   (terminal; uses CancelInvoice by MARK)
 *     refreshStatus(): READ-ONLY reconcile against AADE §8.22 (no new mark row).
 *
 * Return leg (v2.0.2): REGISTER_TRANSFER_RETURN / IN_TRANSIT_RETURN are
 * CARRIER-REPORTED — firebed exposes no submit action for them, so we only
 * OBSERVE them via refreshStatus (→ in_transit_return state + a timeline event).
 * The issuer closes a return with confirmReturn (ConfirmDeliveryReturn, → returned).
 *
 * PARTIALLY SANDBOX-VALIDATED (Β' Φάση rehearsal, 2026-09-13, `myip` on the AADE
 * test env — see `docs/delivery-sandbox-rehearsal.md`). Confirmed from the ISSUER's
 * own credentials on a plain 9.3:
 *   - RegisterTransfer (registered → in_transit): ✓ accepted.
 *   - refreshStatus / RequestDeliveryNoteStatus: ✓ (IN_TRANSIT mapped, lifecycleHistory parses).
 *   - cancel from `registered` (pre-transfer): ✓ (provider path — InvoSign cancel).
 *   - cancel from `in_transit`: ✗ AADE [801] (blocked once moving) — expected & surfaced.
 *   - ConfirmDeliveryReturn from `in_transit`: ✗ AADE [828] → `in_transit` PRUNED from
 *     CONFIRM_RETURN_FROM_STATES.
 * TWO-PARTY validated (2026-09-13, myip ISSUER ⇄ nexon RECIPIENT/CARRIER — see
 * `docs/delivery-two-party-sandbox.md`):
 *   - recipient ConfirmDeliveryOutcome(FULL) → Completed → `delivered` ✓.
 *   - carrier ConfirmDeliveryOutcome(NONE) → FailedDelivery → `failed` ✓ (recipient NONE = [817]).
 *   - carrier ConfirmDeliveryOutcome(PARTIAL) → DeliveredByCarrier → `partial` ✓ (needs [814] deliveredPackaging).
 *   - confirmReturn CONFIRMED from `rejected`, `failed`, AND DeliveredByCarrier(PARTIAL)
 *     (AADE posts a deliveryReturnMark) — the last drove the DELIVERED_BY_CARRIER split
 *     in deliveryStateFromAade (was collapsed to `delivered`, blocking the return).
 * ROLE NOTES (empirical): the CARRIER is whoever CALLS RegisterTransfer, not the declared
 * `carrierVatNumber`. UPDATE 2026-09-25 (sandbox, «ίδια μέσα»): the issuer that CALLED
 * RegisterTransfer itself IS the carrier and CAN ConfirmDeliveryOutcome — FULL +
 * nonObligatedRecipient → Completed, FULL on an obligated B2B → DeliveredByCarrier (the
 * 2026-09-13 [833] was an issuer confirming WITHOUT having registered the transfer). A
 * THIRD-PARTY carrier's / the recipient's own calls stay with their ERP (not wired here).
 */
class DeliveryLifecycleService
{
    public function __construct(
        private readonly Company $tenant,
        /** Optional Guzzle MockHandler for tests — mirrors DeliveryNoteSubmitter. */
        private readonly ?MockHandler $mockHandler = null,
    ) {}

    /**
     * §8.22-ish local state map → Greek label (for the UI / notifications).
     * The keys are OUR delivery_state strings, not AADE's enum (which is mapped
     * in deliveryStateFromAade()).
     *
     * @var array<string, string>
     */
    public const STATE_LABELS = [
        'registered' => 'Εκδόθηκε',
        'in_transit' => 'Σε διακίνηση',
        'in_transit_return' => 'Σε διακίνηση (επιστροφή)', // v2.0.2 DeliveryStatus::IN_TRANSIT_RETURN (9)
        'delivered' => 'Παραδόθηκε',
        // DeliveredByCarrier + FULL: the carrier (we, with our own vehicle) delivered;
        // an OBLIGATED B2B recipient still has to confirm by scanning the QR.
        'awaiting_recipient' => 'Παραδόθηκε — αναμένεται ο παραλήπτης',
        'partial' => 'Μερική παράδοση',
        'failed' => 'Αποτυχία παράδοσης',
        'returned' => 'Επιστράφηκε',
        'rejected' => 'Απορρίφθηκε',
        'cancelled' => 'Ακυρώθηκε',
    ];

    /**
     * Movement states AADE can still move on its own (another party acts: the recipient
     * scans / rejects, a carrier starts / delivers / returns) — what the scheduled
     * delivery:refresh-status re-reads. Terminal ones (delivered / returned / cancelled)
     * and the ones only WE advance (failed / partial / rejected → confirmReturn) are out.
     */
    public const OPEN_STATES = ['registered', 'in_transit', 'in_transit_return', 'awaiting_recipient'];

    public static function stateLabel(?string $state): ?string
    {
        return $state === null ? null : (self::STATE_LABELS[$state] ?? $state);
    }

    // ---- 1. RegisterTransfer (έναρξη διακίνησης) ------------------------

    /**
     * Δήλωση παραλαβής αγαθών + έναρξη διακίνησης. registered → in_transit.
     * Keyed by the qrUrl (mydata_url). On success stores the transferMark.
     */
    public function registerTransfer(MovableDocument $note): DeliveryMark
    {
        // MYD-022: the lifecycle events are filed under the tenant's ΑΦΜ and
        // credentials just like the issue itself — same fail-closed check.
        $note->assertMovementTenant($this->tenant);

        $this->requireState($note, 'registered', 'Έναρξη διακίνησης');

        if ($note->mydata_state !== 'VALID') {
            throw new RuntimeException(
                "Το δελτίο {$note->invcode} δεν είναι εκδομένο/έγκυρο στο myDATA (state="
                .($note->mydata_state ?: 'null').'). Εκδώστε το πρώτα.'
            );
        }

        $qrUrl = $this->requireQrUrl($note);

        // transportType is MANDATORY (Delivery Note v2.0.2, TransportDetailType,
        // accepts 1–7). The UI requires it, but this service is ALSO reached by
        // console/API/import callers, so gate it here — a silent omission (the old
        // behaviour) just produced an avoidable AADE rejection, and an out-of-range
        // value was dropped rather than surfaced. MYD-013.
        $transportType = $note->transport_type;   // model casts to ?int
        if ($transportType === null || TransportType::tryFrom($transportType) === null) {
            throw new RuntimeException(
                "Το δελτίο {$note->invcode} δεν έχει έγκυρο τρόπο μεταφοράς (transportType 1–7). "
                .'Συμπληρώστε τον πριν την έναρξη διακίνησης.'
            );
        }

        // vehicleNumber is mandatory unless transportType = 7 (Άνευ / χωρίς
        // μεταφορικό μέσο) — validate per the selected type, not blanket.
        $vehicle = trim((string) ($note->vehicle_number ?? ''));
        if ($transportType !== TransportType::WITHOUT->value && $vehicle === '') {
            throw new RuntimeException(
                "Το δελτίο {$note->invcode} απαιτεί αριθμό μεταφορικού μέσου για τον επιλεγμένο "
                .'τρόπο μεταφοράς (υποχρεωτικό για κάθε τύπο εκτός του 7 «Άνευ»).'
            );
        }

        $details = (new TransportDetails)
            ->setCarrierVatNumber(
                $note->carrier_afm
                    ?: $this->tenant->afm
                    ?: throw new RuntimeException('Δεν υπάρχει ΑΦΜ μεταφορέα ούτε ΑΦΜ εταιρείας για τη διακίνηση.')
            )
            ->setTransportType($transportType);

        // type 7 (Άνευ) may carry no vehicle → keep the explicit placeholder the
        // prior behaviour used so AADE still receives a (non-empty) vehicleNumber.
        $details->setVehicleNumber($vehicle !== '' ? $vehicle : 'ΧΩΡΙΣ ΜΕΤΑΦΟΡΙΚΟ ΜΕΣΟ');

        $transport = (new Transport)
            ->setQrUrl($qrUrl)
            ->setTransportDetail($details);

        $action = new RegisterTransfer;
        $response = $this->dispatch($note, 'register_transfer', fn () => $action->handle($transport));
        $first = $this->firstSuccessful($response, 'Έναρξη διακίνησης');

        $mark = $first->getTransferMark();

        return $this->persistEvent(
            $note,
            action: 'REGISTER_TRANSFER',
            mark: $mark !== null ? (string) $mark : null,
            requestXml: $this->requestXml($action),
            responseXml: $action->getResponseXML() ?? '',
            cache: [
                'delivery_state' => 'in_transit',
                'transfer_mark' => $mark !== null ? (string) $mark : null,
            ],
        );
    }

    // ---- 2. ConfirmDeliveryOutcome — «ίδια μέσα» (we are the carrier) ---

    /**
     * «Παραδόθηκε (ίδιο όχημα)» — ConfirmDeliveryOutcome by the CARRIER. The carrier
     * is whoever CALLED RegisterTransfer (credentials, not the carrierVatNumber field),
     * so this is offered ONLY when WE started the movement (our transfer_mark) and the
     * δελτίο is in_transit — the «ίδια μέσα» case (Ε.2030/2025: εκδότης = αποστολέας,
     * δεν υφίσταται τρίτος μεταφορέας).
     *
     * Sandbox-proven 2026-09-25 (supersedes the 2026-09-13 «issuer can never confirm»
     * [833] note, which confirmed WITHOUT having registered the transfer itself):
     *   - FULL + header nonObligatedRecipient → AADE COMPLETED  → `delivered`;
     *   - FULL, obligated B2B recipient        → DELIVERED_BY_CARRIER → `awaiting_recipient`
     *     (the recipient closes it by scanning the QR — Α.1122/2024 άρθ.3 §4);
     *   - NONE                                 → FAILED_DELIVERY → `failed` (then «Δήλωση επιστροφής»).
     * PARTIAL is NOT offered: AADE requires [814] deliveredPackaging, which we don't model yet.
     */
    public function confirmOutcome(MovableDocument $note, DeliveryOutcomeType $outcome): DeliveryMark
    {
        $note->assertMovementTenant($this->tenant);

        $this->requireState($note, 'in_transit', 'Δήλωση παράδοσης');

        if (! self::isOwnVehicleCarrier($note, $this->tenant)) {
            throw new RuntimeException(
                "Δήλωση παράδοσης: η διακίνηση του {$note->invcode} δεν μεταφέρεται από εμάς (χωρίς δική μας «Έναρξη "
                .'διακίνησης» ή με άλλον ΑΦΜ μεταφορέα) — την παράδοση τη δηλώνει ο μεταφορέας ή ο παραλήπτης.'
            );
        }

        if ($outcome === DeliveryOutcomeType::PARTIAL) {
            throw new RuntimeException('Η μερική παράδοση θέλει στοιχεία συσκευασίας (deliveredPackaging) — δεν υποστηρίζεται ακόμα.');
        }

        $deliveryOutcome = (new DeliveryOutcome)
            ->setQrUrl($this->requireQrUrl($note))
            ->setOutcome($outcome);

        $action = new ConfirmDeliveryOutcome;
        $response = $this->dispatch($note, 'confirm_outcome', fn () => $action->handle($deliveryOutcome));
        $first = $this->firstSuccessful($response, 'Δήλωση παράδοσης');

        $mark = $first->getDeliveryOutcomeMark();
        $state = match (true) {
            $outcome === DeliveryOutcomeType::NONE => 'failed',
            (bool) $note->non_obligated_recipient => 'delivered',
            default => 'awaiting_recipient',
        };

        $audit = $this->persistEvent(
            $note,
            action: 'CONFIRM_OUTCOME',
            mark: $mark !== null ? (string) $mark : null,
            requestXml: $this->requestXml($action),
            responseXml: $action->getResponseXML() ?? '',
            cache: ['delivery_state' => $state, 'outcome_mark' => $mark !== null ? (string) $mark : null],
        );

        // The state above is our best guess from the local flag; AADE is the truth (e.g.
        // a private recipient may complete even without the flag). One read-only status
        // query settles it — best-effort: the declaration itself already succeeded.
        try {
            $this->refreshStatus($note);
        } catch (Throwable $e) {
            Log::info('confirmOutcome: post-declaration status refresh skipped', [
                'note' => $note->invcode, 'error' => $e->getMessage(),
            ]);
        }

        return $audit;
    }

    /**
     * «Ίδια μέσα»: did WE start this movement as its carrier? We called RegisterTransfer
     * (our transfer_mark) with our own ΑΦΜ as carrier — not a courier's ΑΦΜ in carrier_afm
     * (registerTransfer sends `carrier_afm ?: tenant afm`). Shared by the service guard and
     * the UI visibility so the two can't drift.
     */
    public static function isOwnVehicleCarrier(MovableDocument $note, Company $tenant): bool
    {
        $carrier = trim((string) ($note->carrier_afm ?? ''));

        return filled($note->transfer_mark)
            && ($carrier === '' || $carrier === trim((string) $tenant->afm));
    }

    // ---- 2b. ConfirmDeliveryReturn (δήλωση επιστροφής) -----------------

    /**
     * States the issuer's ConfirmDeliveryReturn may be called from (DGM v2.0.2
     * §3.2.7 «Προηγούμενη Κατάσταση»). For a PLAIN 9.3 δελτίο (our case — not 9.2,
     * not `reverseDeliveryNote`) the spec lists **Rejected / DeliveredByCarrier
     * (PARTIAL) / FailedDelivery** → our `rejected`/`partial`/`failed`.
     *
     * `in_transit` was PRUNED after the Β' Φάση sandbox rehearsal
     * (`docs/delivery-sandbox-rehearsal.md`, 2026-09-13): AADE rejects
     * ConfirmDeliveryReturn from a plain-9.3 InTransit with **[828]** «Cannot call
     * ConfirmDeliveryReturn … because of its current delivery status: InTransit».
     * Since `in_transit` is the ONE such state the issuer can actually reach on its
     * own (RegisterTransfer → in_transit), keeping it only offered an operator an
     * action that always [828]-fails.
     *
     * `in_transit_return` is KEPT: it is a DIFFERENT AADE status (IN_TRANSIT_RETURN,
     * the carrier-reported return leg — §3.2.7's 9.3-reverse case) that a single
     * issuer tenant cannot reach in the sandbox (the return leg is carrier-driven),
     * so the rehearsal could neither confirm nor disprove it. Keeping it is
     * fail-safe (a wrong source is rejected by AADE at `firstSuccessful`, never
     * corrupts state) and matches the one in-transit-family source §3.2.7 allows.
     *
     * @var list<string>
     */
    public const CONFIRM_RETURN_FROM_STATES = ['rejected', 'partial', 'failed', 'in_transit_return'];

    /**
     * Δήλωση ολοκλήρωσης διακίνησης ΕΠΙ ΕΠΙΣΤΡΟΦΗΣ (myDATA v2.0.2): ο εκδότης δηλώνει
     * ότι η διακίνηση έκλεισε με επιστροφή (ο μεταφορέας δεν παρέδωσε όλα τα αγαθά).
     * `{rejected|partial|failed|in_transit_return} → returned` (βλ.
     * CONFIRM_RETURN_FROM_STATES + DGM v2.0.2 §3.2.7). Keyed by the qrUrl· με επιτυχία
     * η ΑΑΔΕ φέρνει το `deliveryReturnMark` και το δελτίο μεταβαίνει σε Completed.
     * Αυτός είναι ο durable attempt-record που περίμενε το DEP-001.
     */
    public function confirmReturn(MovableDocument $note): DeliveryMark
    {
        // MYD-022: filed under the tenant's ΑΦΜ + credentials, like every event.
        $note->assertMovementTenant($this->tenant);

        $this->requireStateIn($note, self::CONFIRM_RETURN_FROM_STATES, 'Δήλωση επιστροφής');

        $deliveryReturn = (new DeliveryReturn)->setQrUrl($this->requireQrUrl($note));

        $action = new ConfirmDeliveryReturn;
        $response = $this->dispatch($note, 'confirm_return', fn () => $action->handle($deliveryReturn));
        $first = $this->firstSuccessful($response, 'Δήλωση επιστροφής');

        $mark = $first->getDeliveryReturnMark();

        return $this->persistEvent(
            $note,
            action: 'CONFIRM_RETURN',
            mark: $mark !== null ? (string) $mark : null,
            requestXml: $this->requestXml($action),
            responseXml: $action->getResponseXML() ?? '',
            cache: [
                'delivery_state' => 'returned',
                'return_mark' => $mark !== null ? (string) $mark : null,
            ],
        );
    }

    // ---- 3. RequestDeliveryNoteStatus (έλεγχος κατάστασης) -------------

    /**
     * READ-ONLY reconcile against AADE §8.22 by the issue MARK + issuer AFM.
     * Maps the returned DeliveryStatus to our delivery_state and forceFills it
     * (no new delivery_marks row — this is a query, not an event).
     *
     * @return array{aade_status: ?DeliveryStatus, aade_label: ?string, mapped_state: ?string, changed: bool}
     */
    public function refreshStatus(MovableDocument $note): array
    {
        // MYD-022: the lifecycle events are filed under the tenant's ΑΦΜ and
        // credentials just like the issue itself — same fail-closed check.
        $note->assertMovementTenant($this->tenant);

        if (empty($note->mydata_mark)) {
            throw new RuntimeException(
                "Το δελτίο {$note->invcode} δεν έχει MARK — δεν έχει εκδοθεί στο myDATA."
            );
        }

        $issuerAfm = $this->tenant->afm
            ?? throw new RuntimeException('Η εταιρεία δεν έχει ΑΦΜ — αδύνατος ο έλεγχος κατάστασης.');

        $action = new RequestDeliveryNoteStatus;

        $this->initFirebed();

        try {
            /** @var DeliveryNoteStatusResponse $response */
            $response = $action->handle((int) $note->mydata_mark, $issuerAfm);
        } catch (MyDataAuthenticationException $e) {
            $this->logFailure($note, 'auth', $e);
            throw new RuntimeException('Το myDATA απέρριψε τα διαπιστευτήρια. Ελέγξτε Company → myDATA.', 0, $e);
        } catch (MyDataTimeoutException|MyDataConnectionException $e) {
            $this->logFailure($note, 'transport', $e);
            throw new RuntimeException('Το myDATA δεν είναι προσβάσιμο. Δοκιμάστε ξανά αργότερα.', 0, $e);
        } catch (MyDataException $e) {
            $this->logFailure($note, 'protocol', $e);
            throw new RuntimeException('Ο έλεγχος κατάστασης απέτυχε: '.$e->getMessage(), 0, $e);
        } catch (Throwable $e) {
            $this->logFailure($note, 'other', $e);
            throw new RuntimeException('Ο έλεγχος κατάστασης απέτυχε απρόσμενα.', 0, $e);
        }

        $aadeStatus = $response->getStatus();
        $mappedState = $this->deliveryStateFromAade($aadeStatus, $response->getLifecycleHistory());

        $changed = false;
        $stateSynced = false;

        if ($aadeStatus === DeliveryStatus::CANCELLED) {
            // MYD-019: a TERMINAL AADE cancellation (typically performed outside
            // ekdosi — straight from the myDATA portal) must apply to ALL THREE
            // state fields, leave a forensic STATE_SYNC audit row and run the SAME
            // stock compensation as a local/provider cancel — not merely flip the
            // delivery_state cache and leave mydata_state=VALID / local_status=active
            // (the split state that made different screens disagree). Idempotent.
            //
            // Combined ΤΔΑ (§7): a monetary movable is ONE 1.1 document/MARK, so its
            // remote cancel is owned by the MONETARY choke-point (SyncInvoiceStateFromAade
            // — the invoice twin of applyRemoteCancellation), NOT the DN-typed path that
            // would write a DeliveryMark STATE_SYNC row in the wrong audit table and
            // collide with the monetary sync. A money-less DeliveryNote keeps the DN path.
            $stateSynced = $note instanceof Invoice
                ? $this->applyRemoteCancellationMonetary($note)
                : $this->applyRemoteCancellation($note);
            $changed = $stateSynced;
        } elseif ($mappedState !== null && $mappedState !== $note->delivery_state) {
            // Non-terminal remote state: keep the delivery_state cache fresh — but
            // NEVER resurrect a business-cancelled δελτίο. A note that is already
            // cancelled (locally or at AADE) must not have its terminal cache
            // overwritten by a stale non-terminal tracking status the feed still
            // reports (MYD-019: "do not automatically resurrect"). Same for a
            // 'returned' note: AADE reports it as Completed, which maps to
            // 'delivered' — but a return-completion is NOT a plain delivery, so
            // its terminal cache must survive a refresh.
            if ($note->local_status !== 'cancelled'
                && $note->mydata_state !== 'CANCELLED'
                && $note->delivery_state !== 'returned') {
                $note->forceFill(['delivery_state' => $mappedState])->save();
                $changed = true;
            }
        }

        $eventsSynced = $this->syncLifecycleHistory($note, $response->getLifecycleHistory());

        return [
            'aade_status' => $aadeStatus,
            'aade_label' => $aadeStatus?->label(),
            'mapped_state' => $mappedState,
            'changed' => $changed,
            // True ONLY when a terminal AADE cancellation was applied from the
            // refresh — the UI surfaces this as a warning, not a plain «no change».
            'state_synced' => $stateSynced,
            'events_synced' => $eventsSynced,
        ];
    }

    /**
     * Persist the §4.1 lifecycleHistory (the carrier/recipient timeline) into
     * `delivery_note_events`, idempotent on `dedup_key`. Discarding it was the
     * one gap left after PR #179: the issuer could see the CURRENT status but
     * not WHAT the carrier & recipient did. Re-running refreshStatus upserts
     * (never duplicates). Returns the number of events seen in this response.
     *
     * @param  DeliveryEvent[]|null  $events
     */
    public function syncLifecycleHistory(MovableDocument $note, ?array $events): int
    {
        if (empty($events)) {
            return 0;
        }

        DB::transaction(function () use ($note, $events) {
            foreach ($events as $event) {
                if (! $event instanceof DeliveryEvent) {
                    continue;
                }

                $type = $event->getEventType()?->value ?? 'Unknown';
                $timestamp = $event->getEventTimestamp();
                $actor = $event->getActorVat();
                $mark = $event->getMark();

                $dedupKey = $mark !== null
                    ? (string) $mark
                    : substr(hash('sha256', $type.'|'.($timestamp ?? '').'|'.($actor ?? '')), 0, 64);

                // Combined ΤΔΑ (3c): morph-keyed idempotency. Writing THROUGH the
                // polymorphic relation scopes the match by (movable_type, movable_id)
                // — the (movable_type, movable_id, dedup_key) unique (3c-1) — so an
                // Invoice-backed ΤΔΑ event dedups on ITS morph, not a NULL
                // delivery_note_id. For a DeliveryNote the MirrorsMovableFromDeliveryNote
                // hook back-fills delivery_note_id so the FK readers still see it.
                $note->movementEvents()->updateOrCreate(
                    ['dedup_key' => $dedupKey],
                    [
                        'company_id' => $note->company_id,
                        'event_mark' => $mark,
                        'event_type' => $type,
                        'event_timestamp' => $timestamp,
                        'actor_vat' => $actor,
                        'details' => $this->eventDetails($event),
                    ],
                );
            }
        });

        return count($events);
    }

    /**
     * Flatten the populated transport/outcome/rejection block into a JSON array.
     * Stores CODES only (transport_type int, outcome string) — Greek labels are
     * rendered at display time via DeliveryCodes in DeliveryNoteEvent::summary(),
     * so historical rows never freeze a stale label.
     */
    private function eventDetails(DeliveryEvent $event): ?array
    {
        if ($transport = $event->getTransportDetails()) {
            return array_filter([
                'vehicle_number' => $transport->getVehicleNumber(),
                'carrier_vat' => $transport->getCarrierVatNumber(),
                'transport_type' => $transport->getTransportType()?->value,
                'timestamp' => $transport->getTimestamp(),
            ], static fn ($v) => $v !== null);
        }

        if ($outcome = $event->getOutcomeDetails()) {
            return array_filter([
                'outcome' => $outcome->getOutcome()?->value,
                'delivered_without_recipient' => $outcome->getDeliveredWithoutRecipient(),
            ], static fn ($v) => $v !== null);
        }

        if ($rejection = $event->getRejectionDetails()) {
            return array_filter(['reason' => $rejection->getReason()], static fn ($v) => $v !== null);
        }

        return null;
    }

    // ---- 4. Cancel (ακύρωση) ------------------------------------------

    /**
     * Ακύρωση δελτίου. Uses CancelInvoice by the issue MARK (mirrors
     * MyDataSubmitter::cancel — the provider-only CancelDeliveryNote throws on
     * the ERP route, so a 9.x δελτίο is cancelled the same way as an invoice).
     * filed → cancelled (terminal).
     */
    public function cancel(DeliveryNote $note, string $reason = ''): DeliveryMark
    {
        // Cancel stays DeliveryNote-typed (§7): a monetary ΤΔΑ cancels through the
        // monetary path (MyDataSubmitter::cancel), so an Invoice is refused here at
        // the call boundary. MYD-022: filed under the tenant's ΑΦΜ + credentials.
        $note->assertMovementTenant($this->tenant);

        if (empty($note->mydata_mark)) {
            throw new RuntimeException(
                "Το δελτίο {$note->invcode} δεν έχει MARK — δεν έχει εκδοθεί στο myDATA."
            );
        }
        if ($note->mydata_state === 'CANCELLED') {
            throw new RuntimeException(
                "Το δελτίο {$note->invcode} είναι ήδη ακυρωμένο στο myDATA (state=CANCELLED)."
            );
        }

        // Cancel the INSERT MARK from the audit history — same reasoning as
        // MyDataSubmitter::cancel (a retried submit could have produced a 2nd
        // MARK while the mirror reflects only the latest).
        $inserts = DeliveryMark::query()
            ->where('delivery_note_id', $note->id)
            ->whereIn('mydata_action', ['INSERT', 'PROVIDER_INSERT']) // provider issuance writes PROVIDER_INSERT
            ->whereNotNull('mark')
            ->orderByDesc('id')
            ->get();

        if ($inserts->isEmpty()) {
            throw new RuntimeException(
                "Αδύνατη η ακύρωση του δελτίου {$note->invcode} — δεν υπάρχει INSERT MARK στο ιστορικό."
            );
        }
        if ($inserts->count() > 1) {
            Log::warning('myDATA delivery cancel: multiple INSERT MARKs — cancelling latest only', [
                'delivery_note_id' => $note->id,
                'invcode' => $note->invcode,
                'marks' => $inserts->pluck('mark')->all(),
            ]);
        }

        $markToCancel = (string) $inserts->first()->mark;

        // Ακύρωση μέσω παρόχου: ο InvoSign εκθέτει iNVOSign_CancelDeliveryNote, οπότε
        // για provider tenant η ακύρωση πάει στο ΙΔΙΟ κανάλι με την έκδοση (όπως
        // GrProviderSubmitter::cancel για τα τιμολόγια). Η έναρξη/παράδοση/έλεγχος
        // ΔΕΝ προσφέρονται από τον πάροχο → μένουν απευθείας myDATA.
        if ($this->tenant->isLiveProviderTenant()) {
            return $this->cancelViaProvider($note, $markToCancel, $reason);
        }

        $action = new CancelInvoice;
        $response = $this->dispatch($note, 'cancel', fn () => $action->handle($markToCancel));

        // Assert AADE accepted the cancel BEFORE flipping the note to the
        // terminal cancelled state. CancelInvoice::handle does not throw on a
        // business-error body, so without this a rejected cancel (e.g. MARK not
        // found) would be silently recorded as cancelled — and a wrongly-terminal
        // delivery_state is hard to recover. register/confirm already assert
        // Success via firstSuccessful(); keep cancel symmetric.
        $first = $response->first();
        if ($first === null || $first->getStatusCode() !== 'Success') {
            $errors = $first ? $this->describeResponseErrors($first) : 'καμία απάντηση';
            throw new RuntimeException("Ακύρωση: το myDATA απέρριψε την ενέργεια — {$errors}");
        }

        $responseXml = $action->getResponseXML() ?? '';

        // AADE returns its OWN MARK for the cancellation act. Record it in its own
        // column instead of leaving the row claiming the issue MARK is the proof.
        return $this->persistCancellation(
            $note,
            $markToCancel,
            $reason,
            $responseXml,
            null,
            $first->getCancellationMark(),
        );
    }

    /**
     * Ακύρωση δελτίου μέσω του ΥΠΑΗΕΣ παρόχου (InvoSign iNVOSign_CancelDeliveryNote).
     * Twin of GrProviderSubmitter::cancel: POST the issue MARK to the provider, and
     * only on a Success result flip the note to the terminal cancelled state +
     * write the CANCEL audit row. A provider rejection/exception throws (no false
     * cancel), mirroring the direct path (which logs via logFailure too).
     */
    private function cancelViaProvider(DeliveryNote $note, string $markToCancel, string $reason): DeliveryMark
    {
        $transport = app(ProviderTransportRegistry::class)->for((string) $this->tenant->einvoice_provider_key);
        $credentials = ProviderCredentials::fromCompany($this->tenant);

        try {
            $result = $transport->cancel($markToCancel, $credentials, $reason);
        } catch (Throwable $e) {
            $this->logFailure($note, 'provider-cancel', $e);
            throw new RuntimeException('Ακύρωση μέσω παρόχου απέτυχε: '.$e->getMessage(), 0, $e);
        }

        if (! $result->success) {
            throw new RuntimeException('Ακύρωση: ο πάροχος απέρριψε την ενέργεια — '.$result->errorMessage());
        }

        return $this->persistCancellation(
            $note,
            // The document being cancelled. It used to receive
            // `$result->cancellationMark ?? $markToCancel`, which silently
            // relabelled the issue MARK as cancellation evidence whenever the
            // provider returned none — and the provider path is the one that
            // becomes mandatory. The two now go to their own columns.
            $markToCancel,
            $reason,
            $result->raw,
            $transport->key(),
            $result->cancellationMark,
        );
    }

    /**
     * Persist the terminal cancelled state — shared by the direct-myDATA and the
     * provider cancel paths so both leave an IDENTICAL CANCEL audit row + cache
     * flip (only mark / response / provider_key differ).
     *
     * The issue MARK and the CANCELLATION MARK are different evidence and go in
     * different columns (MYD-023). `$mark` is the document being cancelled;
     * `$cancellationMark` is AADE's (or the provider's) own MARK for the
     * cancellation ACT. Before this the issue
     * MARK was written into `mark` on a row whose action is CANCEL, and the
     * provider path fell back to it with `?? $markToCancel` — so the audit trail
     * positively ASSERTED that the issue MARK was the cancellation evidence.
     * That is worse than recording nothing, because it reads as proof.
     *
     * A null `$cancellationMark` is allowed and meaningful: it records that the
     * cancellation happened without one being returned to us. Falling back to the
     * issue MARK is what must not happen.
     */
    private function persistCancellation(
        DeliveryNote $note,
        string $mark,
        string $reason,
        ?string $responseXml,
        ?string $providerKey = null,
        ?string $cancellationMark = null,
    ): DeliveryMark {
        // '' is not evidence — see CancellationMark for why this is one shared rule.
        $cancellationMark = CancellationMark::clean($cancellationMark);

        $audit = DB::transaction(function () use ($note, $mark, $cancellationMark, $reason, $responseXml, $providerKey) {
            $row = DeliveryMark::create(array_filter([
                'company_id' => $note->company_id,
                'delivery_note_id' => $note->id,
                'mark' => $mark,
                'cancellation_mark' => $cancellationMark,
                'mydata_action' => 'CANCEL',
                'provider_key' => $providerKey,
                'request' => $reason !== '' ? "Cancel reason: {$reason}" : null,
                'response' => $responseXml,
                'mark_date' => now()->toDateString(),
                'mark_time' => now()->toTimeString(),
            ], static fn ($v) => $v !== null));

            $note->forceFill([
                'mydata_state' => 'CANCELLED',
                'delivery_state' => 'cancelled',
                'local_status' => 'cancelled',
            ])->save();

            return $row;
        });

        // STOCK-001: a cancelled Πώληση δελτίο returns its goods to stock. BEST-EFFORT
        // and OUTSIDE the state transaction — the AADE/provider cancel already
        // succeeded, so a stock-write hiccup must never surface as a false «cancel
        // failed» (mirrors recordSaleForDeliveryNote on the issue side). Idempotent:
        // a repeat cancel, or a reconciliation-driven remote cancellation (MYD-019),
        // reruns it safely. A no-op for any σκοπός that never moved stock.
        try {
            $note->reverseMovementStock();
        } catch (Throwable $e) {
            Log::warning('Stock reversal after delivery-note cancel failed (the cancel succeeded)', [
                'delivery_note_id' => $note->id,
                'error' => $e->getMessage(),
            ]);
        }

        return $audit;
    }

    /**
     * MYD-019: apply a TERMINAL AADE cancellation discovered via refreshStatus()
     * — the delivery-note twin of SyncInvoiceStateFromAade. Flips ALL THREE state
     * fields atomically, writes a forensic STATE_SYNC audit row (deliberately NOT
     * a CANCEL row, so the history distinguishes «we cancelled it» from «we found
     * it cancelled at AADE and synced»), and runs the SAME idempotent stock
     * compensation as a local/provider cancel (STOCK-001).
     *
     * Idempotent: returns false (a no-op) when the note is already fully terminal,
     * so a repeated refresh never writes a second row nor reverses stock twice.
     *
     * cancellation_mark is left NULL on purpose: RequestDeliveryNoteStatus exposes
     * no cancellation MARK, and the lifecycle history carries no cancellation event
     * (DeliveryEventType is RegisterTransfer/ConfirmOutcome/Rejection/ConfirmReturn/
     * RegisterTransferReturn — none is a cancellation), so we genuinely have none
     * here. Recording null is honest evidence — never a faked MARK (MYD-023). The
     * audit row's text records WHERE the terminal state came from.
     */
    private function applyRemoteCancellation(DeliveryNote $note): bool
    {
        // Fast path: already fully terminal → nothing to sync (idempotent refresh).
        if ($note->mydata_state === 'CANCELLED'
            && $note->local_status === 'cancelled'
            && $note->delivery_state === 'cancelled') {
            return false;
        }

        // Snapshot the pre-sync state for the log now — the transaction below flips
        // $note to terminal before we reach the Log::info.
        $logFrom = [
            'mydata_state' => $note->mydata_state ?: '—',
            'local_status' => $note->local_status ?: '—',
            'delivery_state' => $note->delivery_state ?: '—',
        ];

        // Re-check under a row lock so two concurrent refreshes (a double-click, or a
        // manual «Έλεγχος κατάστασης» overlapping the scheduler) can't each write a
        // STATE_SYNC row for the same cancellation — the legal audit trail must not
        // duplicate. lockForUpdate is real on MariaDB (prod) and a no-op on sqlite
        // (tests). Explicit tenant filter: refreshStatus is reachable from console /
        // queue with no ambient CompanyContext (CLAUDE.md CLI/queue rule).
        $applied = DB::transaction(function () use ($note): bool {
            $locked = DeliveryNote::query()
                ->where('company_id', $this->tenant->getKey())
                ->whereKey($note->getKey())
                ->lockForUpdate()
                ->first();

            if ($locked === null) {
                return false;
            }
            if ($locked->mydata_state === 'CANCELLED'
                && $locked->local_status === 'cancelled'
                && $locked->delivery_state === 'cancelled') {
                return false; // another refresh already synced it under the lock
            }

            $fromMydata = $locked->mydata_state ?: '—';
            $fromLocal = $locked->local_status ?: '—';
            $fromDelivery = $locked->delivery_state ?: '—';

            DeliveryMark::create([
                'company_id' => $note->company_id,
                'delivery_note_id' => $note->id,
                'mark' => $note->mydata_mark,
                'mydata_action' => 'STATE_SYNC',
                'request' => "Συγχρονισμός κατάστασης από ΑΑΔΕ: mydata_state {$fromMydata} → CANCELLED "
                    ."(local_status {$fromLocal} → cancelled, delivery_state {$fromDelivery} → cancelled).",
                'response' => 'Εντοπίστηκε ΑΚΥΡΩΜΕΝΟ στην ΑΑΔΕ κατά τον «Έλεγχο κατάστασης». '
                    .'ΔΕΝ ακυρώθηκε από την εφαρμογή — η ακύρωση έγινε εκτός ekdosi και συγχρονίστηκε.',
                'mark_date' => now()->toDateString(),
                'mark_time' => now()->toTimeString(),
            ]);

            // Write through the caller's model so the refreshStatus return + UI reflect
            // the new state without a re-read (same DB row the lock protects).
            $note->forceFill([
                'mydata_state' => 'CANCELLED',
                'delivery_state' => 'cancelled',
                'local_status' => 'cancelled',
            ])->save();

            return true;
        });

        if (! $applied) {
            return false;
        }

        // Same idempotent business compensation as persistCancellation (STOCK-001).
        // Best-effort + OUTSIDE the transaction: the terminal state is AADE's truth
        // and already persisted, so a stock-write hiccup must never undo the sync.
        try {
            $note->reverseMovementStock();
        } catch (Throwable $e) {
            Log::warning('Stock reversal after remote-detected delivery cancel failed (state synced)', [
                'delivery_note_id' => $note->id,
                'error' => $e->getMessage(),
            ]);
        }

        Log::info('myDATA delivery state synced from AADE (remote cancellation)', [
            'company_id' => $this->tenant->getKey(),
            'delivery_note_id' => $note->id,
            'invcode' => $note->invcode,
            'mark' => $note->mydata_mark,
            'from' => $logFrom,
        ]);

        return true;
    }

    /**
     * Combined ΤΔΑ (§7): a TERMINAL AADE cancellation of a MONETARY movable
     * (`is_delivery_note` Invoice) discovered via refreshStatus is owned by the
     * MONETARY choke-point, not the DN-typed applyRemoteCancellation. Delegating to
     * SyncInvoiceStateFromAade keeps ONE truth for an invoice remote-cancel: it flips
     * mydata_state + local_status, writes the STATE_SYNC row into `mydata_marks` (the
     * RIGHT audit table for an invoice), reflects the cancel on the WHMCS side, and —
     * via the InvoiceObserver's `local_status → cancelled` transition — reverses stock
     * ONCE (so there is deliberately no reverseMovementStock() call here; a second
     * would double-reverse). This service then reconciles the movement CACHE
     * (`delivery_state`), which the money-only sync does not touch.
     *
     * Idempotent: a no-op (returns false) once the ΤΔΑ is already fully terminal, so a
     * repeated refresh writes no second row.
     */
    private function applyRemoteCancellationMonetary(Invoice $invoice): bool
    {
        // Fast path: already fully terminal (money + movement) → nothing to sync.
        if ($invoice->mydata_state === 'CANCELLED'
            && $invoice->local_status === 'cancelled'
            && $invoice->delivery_state === 'cancelled') {
            return false;
        }

        // Monetary choke-point: owns mydata_state/local_status + STATE_SYNC row +
        // WHMCS write-back + (via the observer) stock. No cancellation MARK is exposed
        // by RequestDeliveryNoteStatus, so none is passed (honest «no evidence»).
        //
        // NOT row-locked here (unlike the DN twin applyRemoteCancellation): SyncInvoice-
        // StateFromAade fires a WHMCS write-back HTTP call after its DB write, so wrapping
        // it in a lockForUpdate transaction would hold the row lock across network I/O.
        // The unguarded no-op means two overlapping refreshes could each write a STATE_SYNC
        // row — a duplicate FORENSIC row (state still converges; nothing money-wrong). This
        // path IS reachable now — the invoice-view «Έλεγχος κατάστασης» and the scheduled
        // delivery:refresh-status (withoutOverlapping) — so the overlap needs a manual click
        // racing the scheduler; the lock (done right — WHMCS call outside it) stays deferred.
        $result = app(SyncInvoiceStateFromAade::class)->sync($invoice, 'CANCELLED');

        // Reconcile the movement cache too — SyncInvoiceStateFromAade is money-only and
        // never touches delivery_state. GUARDED column → forceFill.
        $deliveryChanged = false;
        if ($invoice->delivery_state !== 'cancelled') {
            $invoice->forceFill(['delivery_state' => 'cancelled'])->save();
            $deliveryChanged = true;
        }

        return $result['changed'] || $deliveryChanged;
    }

    // ---- internals ----------------------------------------------------

    /**
     * Map AADE §8.22 DeliveryStatus → our delivery_state cache string.
     *
     * The `default => null` arm is load-bearing: myDATA v2.0.2 added
     * DeliveryStatus::IN_TRANSIT_RETURN (9), and any future spec can add more.
     * Before, an unknown code was `tryFrom() === null` and hit the null arm; now
     * it resolves to a real enum case, so WITHOUT a default this match would throw
     * UnhandledMatchError on a status refresh. Unknown/unmodelled statuses map to
     * null → the caller leaves the cache unchanged (never crashes). IN_TRANSIT_RETURN
     * (9) maps to its OWN 'in_transit_return' state (Slice 2) so the carrier-side
     * return leg is visible to the operator rather than collapsed into 'in_transit'.
     *
     * DELIVERED_BY_CARRIER is direction-sensitive: AADE reports the SAME §8.22 status
     * for a carrier FULL delivery and a carrier PARTIAL one — only the lifecycleHistory
     * ConfirmOutcome event carries the FULL/PARTIAL detail. §3.2.7 lists
     * DeliveredByCarrier(PARTIAL) as a valid ConfirmDeliveryReturn source (AADE-confirmed
     * two-party sandbox 2026-09-13: the return posts a deliveryReturnMark), so a PARTIAL
     * must map to 'partial' (∈ CONFIRM_RETURN_FROM_STATES → the operator can close the
     * return) while a FULL carrier delivery is 'awaiting_recipient' (the recipient's QR scan → Completed → 'delivered'). Hence the
     * $lifecycleHistory argument — the status alone can't tell the two apart.
     */
    private function deliveryStateFromAade(?DeliveryStatus $status, ?array $lifecycleHistory = null): ?string
    {
        return match ($status) {
            DeliveryStatus::REGISTERED => 'registered',
            DeliveryStatus::IN_TRANSIT => 'in_transit',
            DeliveryStatus::IN_TRANSIT_RETURN => 'in_transit_return',
            // FULL by the carrier: the goods arrived, but an obligated recipient still has to
            // confirm (QR) before AADE moves it to COMPLETED → not «delivered» yet.
            DeliveryStatus::DELIVERED_BY_CARRIER => $this->carrierDeliveredPartially($lifecycleHistory) ? 'partial' : 'awaiting_recipient',
            DeliveryStatus::COMPLETED => 'delivered',
            DeliveryStatus::FAILED_DELIVERY => 'failed',
            DeliveryStatus::REJECTED => 'rejected',
            DeliveryStatus::CANCELLED => 'cancelled',
            default => null,
        };
    }

    /**
     * Did the carrier declare a PARTIAL delivery? Reads the outcome of the LATEST
     * ConfirmOutcome event in the lifecycleHistory — the effective one — so a PARTIAL
     * later superseded by a corrective FULL is not misread as still-partial (we take
     * the most recent by eventTimestamp, falling back to document order when a
     * timestamp is missing). Used only to split the ambiguous DELIVERED_BY_CARRIER
     * status (see deliveryStateFromAade).
     *
     * Absent/unreadable outcome detail → false (treat as a full carrier delivery).
     * This is a CONSCIOUS default (BACKLOG P2): a spurious 'delivered' dead-ends an
     * in-app return that AADE would accept, whereas a spurious 'partial' self-corrects
     * (AADE rejects an invalid confirmReturn at firstSuccessful, no state corruption) —
     * but a carrier FULL delivery ALSO reports DeliveredByCarrier, and defaulting to
     * 'partial' would mislabel that common case as a partial delivery + offer a return
     * button. GetDeliveryNoteStatus returns a single note's full (un-paginated) history,
     * so a missing ConfirmOutcome here is a malformed response, not the norm; the
     * authoritative outcome always survives in the delivery_marks / lifecycleHistory.
     *
     * @param  DeliveryEvent[]|null  $lifecycleHistory
     */
    private function carrierDeliveredPartially(?array $lifecycleHistory): bool
    {
        $latest = null;
        $latestTs = null;

        foreach ($lifecycleHistory ?? [] as $event) {
            if (! $event instanceof DeliveryEvent
                || $event->getEventType() !== DeliveryEventType::CONFIRM_OUTCOME) {
                continue;
            }

            $outcome = $event->getOutcomeDetails()?->getOutcome();
            if ($outcome === null) {
                continue;
            }

            // ISO-8601 UTC timestamps compare correctly as strings; a missing one
            // sorts first so a later, timestamped outcome wins, and document order
            // breaks ties (last one seen).
            $ts = (string) ($event->getEventTimestamp() ?? '');
            if ($latest === null || $ts >= $latestTs) {
                $latest = $outcome;
                $latestTs = $ts;
            }
        }

        return $latest === DeliveryOutcomeType::PARTIAL;
    }

    private function requireState(MovableDocument $note, string $expected, string $op): void
    {
        $this->requireStateIn($note, [$expected], $op);
    }

    /**
     * Assert the note is in ONE OF $expected delivery_states, else a Greek refusal
     * naming the allowed states (the multi-state twin of requireState — e.g.
     * confirmReturn accepts both in_transit and in_transit_return).
     *
     * @param  list<string>  $expected
     */
    private function requireStateIn(MovableDocument $note, array $expected, string $op): void
    {
        if (! in_array($note->delivery_state, $expected, true)) {
            $allowed = implode(' / ', array_map(fn (string $s): string => self::stateLabel($s) ?? $s, $expected));
            throw new RuntimeException(
                "{$op}: το δελτίο {$note->invcode} είναι σε κατάσταση '"
                .(self::stateLabel($note->delivery_state) ?? '—')."' και όχι '{$allowed}'. Η ενέργεια δεν επιτρέπεται."
            );
        }
    }

    private function requireQrUrl(MovableDocument $note): string
    {
        $qrUrl = trim((string) $note->mydata_url);
        if ($qrUrl === '') {
            throw new RuntimeException(
                "Το δελτίο {$note->invcode} δεν έχει qrUrl (mydata_url) — το κλειδί του κύκλου ζωής λείπει. "
                .'Επανεκδώστε ή ελέγξτε την έκδοση.'
            );
        }

        return $qrUrl;
    }

    /**
     * Run a firebed DGM (or CancelInvoice) call with the shared try/catch →
     * Greek RuntimeException mapping. $kind feeds the failure log. Returns the
     * firebed ResponseDoc — DGM (RegisterTransfer/ConfirmDeliveryOutcome) OR the
     * plain CancelInvoice one, so it's left untyped (the caller knows which).
     *
     * @param  callable():mixed  $call
     */
    private function dispatch(MovableDocument $note, string $kind, callable $call): mixed
    {
        $this->initFirebed();

        try {
            return $call();
        } catch (MyDataAuthenticationException $e) {
            $this->logFailure($note, 'auth', $e);
            throw new RuntimeException('Το myDATA απέρριψε τα διαπιστευτήρια. Ελέγξτε Company → myDATA.', 0, $e);
        } catch (MyDataTimeoutException|MyDataConnectionException $e) {
            $this->logFailure($note, 'transport', $e);
            throw new RuntimeException('Το myDATA δεν είναι προσβάσιμο. Δοκιμάστε ξανά αργότερα.', 0, $e);
        } catch (MyDataException $e) {
            $this->logFailure($note, $kind, $e);
            throw new RuntimeException('Η ενέργεια διακίνησης απέτυχε: '.$e->getMessage(), 0, $e);
        } catch (Throwable $e) {
            $this->logFailure($note, 'other', $e);
            throw new RuntimeException('Η ενέργεια διακίνησης απέτυχε απρόσμενα.', 0, $e);
        }
    }

    /** Pull the first Success Response or throw a Greek error with the AADE errors. */
    private function firstSuccessful(ResponseDoc $response, string $op): DgmResponse
    {
        /** @var DgmResponse|null $first */
        $first = $response->first();

        if ($first === null || $first->getStatusCode() !== 'Success') {
            $errors = $first ? $this->describeResponseErrors($first) : 'no response';
            throw new RuntimeException("{$op}: το myDATA απέρριψε την ενέργεια — {$errors}");
        }

        return $first;
    }

    private function persistEvent(
        MovableDocument $note,
        string $action,
        ?string $mark,
        string $requestXml,
        string $responseXml,
        array $cache,
    ): DeliveryMark {
        return DB::transaction(function () use ($note, $action, $mark, $requestXml, $responseXml, $cache) {
            // Combined ΤΔΑ (3c): write through the polymorphic relation so the morph
            // keys (movable_type/movable_id) are set for EITHER parent. A DeliveryNote
            // also gets delivery_note_id back-filled by MirrorsMovableFromDeliveryNote.
            $audit = $note->movementMarks()->create([
                'company_id' => $note->company_id,
                'mark' => $mark,
                'mydata_action' => $action,
                'invoice_url' => $note->mydata_url,
                'request' => $requestXml,
                'response' => $responseXml,
                'mark_date' => now()->toDateString(),
                'mark_time' => now()->toTimeString(),
            ]);

            // Lifecycle cache columns are GUARDED — written ONLY here via forceFill.
            $note->forceFill($cache)->save();

            return $audit;
        });
    }

    /** Best-effort request-XML capture (HasRequestDom trait on the DGM actions). */
    private function requestXml(object $action): string
    {
        if (method_exists($action, 'getRequestXml')) {
            try {
                return (string) $action->getRequestXml();
            } catch (Throwable) {
                return '';
            }
        }

        return '';
    }

    private function initFirebed(?MyDataMode $environment = null): void
    {
        $mode = $environment ?? $this->tenant->mydata_mode_enum;

        [$aadeId, $subKey] = $this->tenant->mydataCredentials($mode);

        if (empty($aadeId) || empty($subKey)) {
            throw new RuntimeException(
                'Τα διαπιστευτήρια myDATA δεν είναι ρυθμισμένα για αυτή την εταιρεία ('.$mode->value.').'
            );
        }

        $env = $mode === MyDataMode::Production ? 'prod' : 'dev';

        MyDataRequest::init($aadeId, $subKey, $env);
        MyDataRequest::setHandler($this->mockHandler);
    }

    // Accepts either a DGM Response or a standard invoice Response (CancelInvoice
    // returns the latter) — both expose getStatusCode()/getErrors(), so it's
    // duck-typed via method_exists below.
    private function describeResponseErrors(object $response): string
    {
        $errs = $response->getErrors();
        if ($errs === null) {
            return $response->getStatusCode() ?? 'unknown';
        }
        $messages = [];
        foreach ($errs as $e) {
            $code = method_exists($e, 'getCode') ? $e->getCode() : null;
            $msg = method_exists($e, 'getMessage') ? $e->getMessage() : (string) $e;
            $messages[] = $code ? "[{$code}] {$msg}" : $msg;
        }

        return implode('; ', $messages) ?: ($response->getStatusCode() ?? 'unknown');
    }

    private function logFailure(MovableDocument $note, string $kind, Throwable $e): void
    {
        Log::warning('myDATA delivery-lifecycle failure', [
            'company_id' => $this->tenant->getKey(),
            'movable_type' => $note::class,
            'movable_id' => $note->getKey(),
            'invcode' => $note->invcode,
            'kind' => $kind,
            'exception' => get_class($e),
            'message' => $e->getMessage(),
        ]);
    }
}
