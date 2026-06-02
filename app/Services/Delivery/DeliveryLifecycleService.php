<?php

namespace App\Services\Delivery;

use App\Enums\MyDataMode;
use App\Models\Company;
use App\Models\DeliveryMark;
use App\Models\DeliveryNote;
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
        $this->requireState($note, 'registered', 'Έναρξη διακίνησης');

        if ($note->mydata_state !== 'VALID') {
            throw new RuntimeException(
                "Το δελτίο {$note->invcode} δεν είναι εκδομένο/έγκυρο στο myDATA (state="
                .($note->mydata_state ?: 'null').'). Εκδώστε το πρώτα.'
            );
        }

        $qrUrl = $this->requireQrUrl($note);

        $details = (new TransportDetails)
            ->setVehicleNumber((string) ($note->vehicle_number ?: 'ΧΩΡΙΣ ΜΕΤΑΦΟΡΙΚΟ ΜΕΣΟ'))
            ->setCarrierVatNumber(
                $note->carrier_afm
                    ?: $this->tenant->afm
                    ?: throw new RuntimeException('Δεν υπάρχει ΑΦΜ μεταφορέα ούτε ΑΦΜ εταιρείας για τη διακίνηση.')
            );

        // transport_type is OPTIONAL on our model; only set it when present + a
        // valid §-enum value (firebed's setTransportType takes int|enum and the
        // writer drops an out-of-range cast → null, so guard it here).
        if ($note->transport_type !== null
            && TransportType::tryFrom((int) $note->transport_type) !== null) {
            $details->setTransportType((int) $note->transport_type);
        }

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

        return [
            'aade_status' => $aadeStatus,
            'aade_label' => $aadeStatus?->label(),
            'mapped_state' => $mappedState,
            'changed' => $changed,
        ];
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
            ->where('mydata_action', 'INSERT')
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

        $action = new CancelInvoice;
        $this->dispatch($note, 'cancel', fn () => $action->handle($markToCancel));

        $responseXml = $action->getResponseXML() ?? '';

        return DB::transaction(function () use ($note, $responseXml, $reason, $markToCancel) {
            $audit = DeliveryMark::create([
                'company_id' => $note->company_id,
                'delivery_note_id' => $note->id,
                'mark' => $markToCancel,
                'mydata_action' => 'CANCEL',
                'request' => $reason !== '' ? "Cancel reason: {$reason}" : null,
                'response' => $responseXml,
                'mark_date' => now()->toDateString(),
                'mark_time' => now()->toTimeString(),
            ]);

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

    private function describeResponseErrors(DgmResponse $response): string
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
