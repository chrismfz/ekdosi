<?php

namespace App\Services\Delivery;

use App\Enums\MyDataMode;
use App\Models\Company;
use App\Models\InboundDeliveryNote;
use Firebed\AadeMyData\Enums\DigitalGoodsMovement\DeliveryStatus;
use Firebed\AadeMyData\Exceptions\MyDataAuthenticationException;
use Firebed\AadeMyData\Exceptions\MyDataConnectionException;
use Firebed\AadeMyData\Exceptions\MyDataException;
use Firebed\AadeMyData\Exceptions\MyDataTimeoutException;
use Firebed\AadeMyData\Http\DigitalGoodsMovement\RejectDeliveryNote;
use Firebed\AadeMyData\Http\DigitalGoodsMovement\RequestDeliveryNoteStatus;
use Firebed\AadeMyData\Http\MyDataRequest;
use Firebed\AadeMyData\Models\DigitalGoodsMovement\DeliveryNoteStatusResponse;
use Firebed\AadeMyData\Models\DigitalGoodsMovement\Response as DgmResponse;
use GuzzleHttp\Handler\MockHandler;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

/**
 * Slice 4b — the operator's recipient-side actions on a staged
 * «Εισερχόμενα Διακίνησης» row.
 *
 * Two AADE-touching actions (reject, refresh) + one local-only (acknowledge).
 * The reject/confirm asymmetry from the sandbox (docs/delivery-inbound-design.md
 * §2) shapes what's here: **reject and refresh work by MARK** (which the inbox
 * has), so they are desk actions. **Confirm-outcome needs the physical-QR qrUrl**
 * and is Slice 4c — deliberately NOT here.
 *
 * Every action asserts the row belongs to the acting tenant and fails closed
 * (MYD-022 discipline). Nothing here mutates the operator disposition of another
 * tenant's row, and the AADE calls run under THIS tenant's credentials.
 */
class InboundDeliveryService
{
    public function __construct(
        private readonly Company $tenant,
        private readonly ?MockHandler $mockHandler = null,
    ) {}

    /**
     * Local-only «Παραλήφθηκε» — the operator marks a new inbound movement as
     * seen/received without touching AADE. Only meaningful from `new`.
     */
    public function acknowledge(InboundDeliveryNote $row): InboundDeliveryNote
    {
        $this->assertTenant($row);

        if ($row->local_state !== InboundDeliveryNote::STATE_NEW) {
            throw new RuntimeException('Μόνο ένα νέο εισερχόμενο μπορεί να επισημανθεί ως παραληφθέν.');
        }

        $row->forceFill(['local_state' => InboundDeliveryNote::STATE_ACKNOWLEDGED])->save();

        return $row;
    }

    /**
     * Ολική απόρριψη (RejectDeliveryNote) — the recipient rejects the received
     * goods. Works by MARK (no physical QR needed). AADE → the doc goes to the
     * terminal Rejected state and returns our rejectMark. Blocked once our row is
     * already terminal.
     */
    public function reject(InboundDeliveryNote $row, ?string $reason = null): InboundDeliveryNote
    {
        $this->assertTenant($row);

        if ($row->isTerminal()) {
            throw new RuntimeException('Το εισερχόμενο είναι ήδη σε τελική κατάσταση — δεν απορρίπτεται ξανά.');
        }

        $mark = $this->requireMark($row);

        $reason = $reason !== null ? trim($reason) : null;
        $reason = ($reason === null || $reason === '') ? null : $reason;

        $action = new RejectDeliveryNote;
        $this->initFirebed();

        $response = $this->guard($row, 'reject', fn () => $action->rejectUsingMark($mark, $reason));

        /** @var DgmResponse|null $first */
        $first = $response->first();
        if ($first === null || $first->getStatusCode() !== 'Success') {
            $errors = $first ? $this->describeErrors($first) : 'no response';
            throw new RuntimeException("Η απόρριψη απορρίφθηκε από το myDATA — {$errors}");
        }

        $row->forceFill([
            'local_state' => InboundDeliveryNote::STATE_REJECTED,
            'reject_mark' => $first->getRejectMark(),
            'aade_delivery_status' => DeliveryStatus::REJECTED->value,
            'last_fetched_at' => now(),
        ])->save();

        return $row;
    }

    /**
     * READ-ONLY «Έλεγχος κατάστασης» (RequestDeliveryNoteStatus) by our MARK +
     * the issuer's ΑΦΜ. Refreshes `aade_delivery_status` + the lifecycle snapshot;
     * a terminal AADE cancellation by the issuer flips our state to
     * `cancelled_by_issuer` (unless we're already terminal — never resurrect).
     *
     * @return array{status: ?DeliveryStatus, label: ?string, changed: bool}
     */
    public function refreshStatus(InboundDeliveryNote $row): array
    {
        $this->assertTenant($row);

        $mark = $this->requireMark($row);

        $action = new RequestDeliveryNoteStatus;
        $this->initFirebed();

        /** @var DeliveryNoteStatusResponse $response */
        $response = $this->guard($row, 'refresh', fn () => $action->handle($mark, $row->issuer_afm));

        $status = $response->getStatus();

        $attributes = ['last_fetched_at' => now()];
        $changed = false;

        if ($status !== null && $status->value !== $row->aade_delivery_status) {
            $attributes['aade_delivery_status'] = $status->value;
            $changed = true;
        }

        // A terminal AADE cancellation by the issuer: reflect it on OUR side, but
        // never resurrect a row we've already closed (reject/confirm).
        if ($status === DeliveryStatus::CANCELLED && ! $row->isTerminal()) {
            $attributes['local_state'] = InboundDeliveryNote::STATE_CANCELLED_BY_ISSUER;
            $changed = true;
        }

        if ($snapshot = DeliveryEventSnapshot::fromEvents($response->getLifecycleHistory())) {
            $attributes['lifecycle'] = $snapshot;
        }

        $row->forceFill($attributes)->save();

        return [
            'status' => $status,
            'label' => $status?->label(),
            'changed' => $changed,
        ];
    }

    private function assertTenant(InboundDeliveryNote $row): void
    {
        if ((int) $row->company_id !== (int) $this->tenant->getKey()) {
            throw new RuntimeException('Το εισερχόμενο ανήκει σε άλλη εταιρεία.');
        }
    }

    private function requireMark(InboundDeliveryNote $row): int
    {
        if ($row->mydata_mark === null || $row->mydata_mark === '') {
            throw new RuntimeException('Το εισερχόμενο δεν έχει MARK — αδύνατη η ενέργεια.');
        }

        return (int) $row->mydata_mark;
    }

    /**
     * Run an AADE call with the shared Greek-error translation the issuer
     * lifecycle uses. Returns the firebed response on success.
     */
    private function guard(InboundDeliveryNote $row, string $kind, callable $call): mixed
    {
        try {
            return $call();
        } catch (MyDataAuthenticationException $e) {
            $this->logFailure($row, 'auth', $e);
            throw new RuntimeException('Το myDATA απέρριψε τα διαπιστευτήρια. Ελέγξτε Company → myDATA.', 0, $e);
        } catch (MyDataTimeoutException|MyDataConnectionException $e) {
            $this->logFailure($row, 'transport', $e);
            throw new RuntimeException('Το myDATA δεν είναι προσβάσιμο. Δοκιμάστε ξανά αργότερα.', 0, $e);
        } catch (MyDataException $e) {
            $this->logFailure($row, $kind, $e);
            throw new RuntimeException('Η ενέργεια απέτυχε: '.$e->getMessage(), 0, $e);
        } catch (Throwable $e) {
            $this->logFailure($row, 'other', $e);
            throw new RuntimeException('Η ενέργεια απέτυχε απρόσμενα.', 0, $e);
        }
    }

    private function describeErrors(DgmResponse $response): string
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

    private function initFirebed(): void
    {
        $mode = $this->tenant->mydata_mode_enum;

        [$aadeId, $subKey] = $this->tenant->mydataCredentials($mode);

        if (empty($aadeId) || empty($subKey)) {
            throw new RuntimeException(
                'Τα διαπιστευτήρια myDATA δεν είναι ρυθμισμένα για αυτή την εταιρεία ('.$mode->value.').'
            );
        }

        MyDataRequest::init($aadeId, $subKey, $mode === MyDataMode::Production ? 'prod' : 'dev');
        MyDataRequest::setHandler($this->mockHandler);
    }

    private function logFailure(InboundDeliveryNote $row, string $kind, Throwable $e): void
    {
        Log::warning('myDATA inbound-delivery action failure', [
            'company_id' => $this->tenant->getKey(),
            'inbound_delivery_note_id' => $row->getKey(),
            'mydata_mark' => $row->mydata_mark,
            'kind' => $kind,
            'error' => $e->getMessage(),
        ]);
    }
}
