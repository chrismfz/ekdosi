<?php

namespace App\Services\Delivery;

use App\Enums\MyDataMode;
use App\Models\Company;
use App\Models\DeliveryMark;
use App\Models\DeliveryNote;
use App\Models\DeliveryNoteEvent;
use App\Services\EInvoice\ProviderTransportRegistry;
use App\Support\EInvoice\ProviderCredentials;
use App\Support\MyData\CancellationMark;
use App\Support\Tenancy\TenantCoherence;
use Firebed\AadeMyData\Enums\DigitalGoodsMovement\DeliveryOutcomeType;
use Firebed\AadeMyData\Enums\DigitalGoodsMovement\DeliveryStatus;
use Firebed\AadeMyData\Enums\DigitalGoodsMovement\TransportType;
use Firebed\AadeMyData\Exceptions\MyDataAuthenticationException;
use Firebed\AadeMyData\Exceptions\MyDataConnectionException;
use Firebed\AadeMyData\Exceptions\MyDataException;
use Firebed\AadeMyData\Exceptions\MyDataTimeoutException;
use Firebed\AadeMyData\Http\CancelInvoice;
use Firebed\AadeMyData\Http\DigitalGoodsMovement\ConfirmDeliveryOutcome;
use Firebed\AadeMyData\Http\DigitalGoodsMovement\RegisterTransfer;
use Firebed\AadeMyData\Http\DigitalGoodsMovement\RequestDeliveryNoteStatus;
use Firebed\AadeMyData\Http\MyDataRequest;
use Firebed\AadeMyData\Models\DigitalGoodsMovement\DeliveryEvent;
use Firebed\AadeMyData\Models\DigitalGoodsMovement\DeliveryNoteStatusResponse;
use Firebed\AadeMyData\Models\DigitalGoodsMovement\DeliveryOutcome;
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
 *     in_transit ──confirmDelivery(FULL)────▶ delivered
 *     in_transit ──confirmDelivery(PARTIAL)─▶ partial
 *     in_transit ──confirmDelivery(NONE)────▶ failed
 *     (any filed) ──cancel──▶ cancelled   (terminal; uses CancelInvoice by MARK)
 *     refreshStatus(): READ-ONLY reconcile against AADE §8.22 (no new mark row).
 *
 * NOT SANDBOX-VALIDATED. Like the DeliveryNoteSubmitter 9.3 payload, the whole
 * DGM lifecycle (RegisterTransfer / ConfirmDeliveryOutcome / GetDeliveryNoteStatus
 * / cancel-by-MARK) is built against the firebed reference shapes + vendor test
 * stubs but has NOT been round-tripped against the AADE sandbox. Confirm live
 * before go-live (esp. that CancelInvoice — and not the provider-only
 * CancelDeliveryNote — is the correct ERP cancel route for a 9.x δελτίο).
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
        'delivered' => 'Παραδόθηκε',
        'partial' => 'Μερική παράδοση',
        'failed' => 'Αποτυχία παράδοσης',
        'rejected' => 'Απορρίφθηκε',
        'cancelled' => 'Ακυρώθηκε',
    ];

    public static function stateLabel(?string $state): ?string
    {
        return $state === null ? null : (self::STATE_LABELS[$state] ?? $state);
    }

    // ---- 1. RegisterTransfer (έναρξη διακίνησης) ------------------------

    /**
     * Δήλωση παραλαβής αγαθών + έναρξη διακίνησης. registered → in_transit.
     * Keyed by the qrUrl (mydata_url). On success stores the transferMark.
     */
    public function registerTransfer(DeliveryNote $note): DeliveryMark
    {
        // MYD-022: the lifecycle events are filed under the tenant's ΑΦΜ and
        // credentials just like the issue itself — same fail-closed check.
        TenantCoherence::assertDeliveryNote($this->tenant, $note);

        $this->requireState($note, 'registered', 'Έναρξη διακίνησης');

        if ($note->mydata_state !== 'VALID') {
            throw new RuntimeException(
                "Το δελτίο {$note->invcode} δεν είναι εκδομένο/έγκυρο στο myDATA (state="
                .($note->mydata_state ?: 'null').'). Εκδώστε το πρώτα.'
            );
        }

        $qrUrl = $this->requireQrUrl($note);

        // transportType is MANDATORY (Delivery Note v2.0.1, TransportDetailType,
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
        $first = $this->firstSuccessful($note, $response, 'Έναρξη διακίνησης');

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

    // ---- 2. ConfirmDeliveryOutcome (δήλωση παράδοσης) ------------------

    /**
     * Δήλωση αποτελέσματος παράδοσης. in_transit → delivered|partial|failed.
     * $outcome ∈ {FULL, PARTIAL, NONE}. Keyed by the qrUrl.
     */
    public function confirmDelivery(DeliveryNote $note, string $outcome = 'FULL'): DeliveryMark
    {
        // MYD-022: the lifecycle events are filed under the tenant's ΑΦΜ and
        // credentials just like the issue itself — same fail-closed check.
        TenantCoherence::assertDeliveryNote($this->tenant, $note);

        $this->requireState($note, 'in_transit', 'Δήλωση παράδοσης');

        $outcomeType = DeliveryOutcomeType::tryFrom(mb_strtoupper(trim($outcome)))
            ?? throw new RuntimeException(
                "Άγνωστο αποτέλεσμα παράδοσης '{$outcome}'. Επιτρεπτά: FULL / PARTIAL / NONE."
            );

        $qrUrl = $this->requireQrUrl($note);

        $deliveryOutcome = (new DeliveryOutcome)
            ->setQrUrl($qrUrl)
            ->setOutcome($outcomeType);

        $action = new ConfirmDeliveryOutcome;
        $response = $this->dispatch($note, 'confirm_outcome', fn () => $action->handle($deliveryOutcome));
        $first = $this->firstSuccessful($note, $response, 'Δήλωση παράδοσης');

        $mark = $first->getDeliveryOutcomeMark();

        $newState = match ($outcomeType) {
            DeliveryOutcomeType::FULL => 'delivered',
            DeliveryOutcomeType::PARTIAL => 'partial',
            DeliveryOutcomeType::NONE => 'failed',
        };

        return $this->persistEvent(
            $note,
            action: 'CONFIRM_OUTCOME',
            mark: $mark !== null ? (string) $mark : null,
            requestXml: $this->requestXml($action),
            responseXml: $action->getResponseXML() ?? '',
            cache: [
                'delivery_state' => $newState,
                'outcome_mark' => $mark !== null ? (string) $mark : null,
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
    public function refreshStatus(DeliveryNote $note): array
    {
        // MYD-022: the lifecycle events are filed under the tenant's ΑΦΜ and
        // credentials just like the issue itself — same fail-closed check.
        TenantCoherence::assertDeliveryNote($this->tenant, $note);

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
        $mappedState = $this->deliveryStateFromAade($aadeStatus);

        $changed = false;
        if ($mappedState !== null && $mappedState !== $note->delivery_state) {
            $note->forceFill(['delivery_state' => $mappedState])->save();
            $changed = true;
        }

        $eventsSynced = $this->syncLifecycleHistory($note, $response->getLifecycleHistory());

        return [
            'aade_status' => $aadeStatus,
            'aade_label' => $aadeStatus?->label(),
            'mapped_state' => $mappedState,
            'changed' => $changed,
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
    public function syncLifecycleHistory(DeliveryNote $note, ?array $events): int
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

                DeliveryNoteEvent::updateOrCreate(
                    ['delivery_note_id' => $note->id, 'dedup_key' => $dedupKey],
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
        // MYD-022: the lifecycle events are filed under the tenant's ΑΦΜ and
        // credentials just like the issue itself — same fail-closed check.
        TenantCoherence::assertDeliveryNote($this->tenant, $note);

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

        return DB::transaction(function () use ($note, $mark, $cancellationMark, $reason, $responseXml, $providerKey) {
            $audit = DeliveryMark::create(array_filter([
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

            return $audit;
        });
    }

    // ---- internals ----------------------------------------------------

    /** Map AADE §8.22 DeliveryStatus → our delivery_state cache string. */
    private function deliveryStateFromAade(?DeliveryStatus $status): ?string
    {
        return match ($status) {
            DeliveryStatus::REGISTERED => 'registered',
            DeliveryStatus::IN_TRANSIT => 'in_transit',
            DeliveryStatus::DELIVERED_BY_CARRIER => 'delivered',
            DeliveryStatus::COMPLETED => 'delivered',
            DeliveryStatus::FAILED_DELIVERY => 'failed',
            DeliveryStatus::REJECTED => 'rejected',
            DeliveryStatus::CANCELLED => 'cancelled',
            null => null,
        };
    }

    private function requireState(DeliveryNote $note, string $expected, string $op): void
    {
        if ($note->delivery_state !== $expected) {
            throw new RuntimeException(
                "{$op}: το δελτίο {$note->invcode} είναι σε κατάσταση '"
                .(self::stateLabel($note->delivery_state) ?? '—')."' και όχι '"
                .(self::stateLabel($expected) ?? $expected)."'. Η ενέργεια δεν επιτρέπεται."
            );
        }
    }

    private function requireQrUrl(DeliveryNote $note): string
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
    private function dispatch(DeliveryNote $note, string $kind, callable $call): mixed
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
    private function firstSuccessful(DeliveryNote $note, ResponseDoc $response, string $op): DgmResponse
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
        DeliveryNote $note,
        string $action,
        ?string $mark,
        string $requestXml,
        string $responseXml,
        array $cache,
    ): DeliveryMark {
        return DB::transaction(function () use ($note, $action, $mark, $requestXml, $responseXml, $cache) {
            $audit = DeliveryMark::create([
                'company_id' => $note->company_id,
                'delivery_note_id' => $note->id,
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

    private function logFailure(DeliveryNote $note, string $kind, Throwable $e): void
    {
        Log::warning('myDATA delivery-lifecycle failure', [
            'company_id' => $this->tenant->getKey(),
            'delivery_note_id' => $note->id,
            'invcode' => $note->invcode,
            'kind' => $kind,
            'exception' => get_class($e),
            'message' => $e->getMessage(),
        ]);
    }
}
