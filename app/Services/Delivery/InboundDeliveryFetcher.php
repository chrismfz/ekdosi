<?php

namespace App\Services\Delivery;

use App\Models\Company;
use App\Models\InboundDeliveryNote;
use App\Models\Supplier;
use App\Services\MyData\ExpenseReconciler;
use App\Services\MyData\FirebedCredentials;
use App\Support\MyData\Codes;
use Carbon\Carbon;
use Firebed\AadeMyData\Enums\DigitalGoodsMovement\DeliveryStatus;
use Firebed\AadeMyData\Http\RequestDocs;
use Firebed\AadeMyData\Models\ContinuationToken;
use Firebed\AadeMyData\Models\DigitalGoodsMovement\DeliveryEvent;
use Firebed\AadeMyData\Models\Invoice;
use Firebed\AadeMyData\Models\InvoiceHeader;
use Firebed\AadeMyData\Models\Issuer;
use Firebed\AadeMyData\Models\OtherDeliveryNoteHeader;
use GuzzleHttp\Handler\MockHandler;
use Illuminate\Database\UniqueConstraintViolationException;

/**
 * Slice 4a — stage the ψηφιακή-διακίνηση documents OTHERS filed against us
 * (goods we are receiving) into «Εισερχόμενα Διακίνησης».
 *
 * READ-ONLY discovery: reuses the exact `RequestDocs` feed + continuationToken
 * pagination that {@see ExpenseReconciler} uses ("docs
 * others filed against us"), then keeps ONLY the movement-bearing docs and
 * upserts them into `inbound_delivery_notes`. It never rejects/confirms/mutates
 * a legal state at AADE — those are the operator-gated Filament actions.
 *
 * The movement discriminator (catches pure 9.x ΔΑ AND combined 1.1 ΤΔΑ AND
 * anything already in the DGM lifecycle): a doc is kept iff it carries an
 * `invoiceDeliveryStatus`, an `otherDeliveryNoteHeader`, OR a movement-only
 * (9.x) §8.1 type. A plain invoice/expense with none of these is skipped.
 *
 * Tenant safety: EVERY query/write scopes explicitly by `company_id` (the
 * command runs outside panel context — no global-scope reliance), per the
 * CLAUDE.md CLI/queue rule. Idempotency: (company_id, mydata_mark), withTrashed
 * so a re-poll of a hidden row never duplicates it.
 */
class InboundDeliveryFetcher
{
    public function __construct(
        private readonly Company $tenant,
        private readonly ?MockHandler $mockHandler = null,
    ) {}

    /**
     * Fetch + stage inbound movements for the window. Dates default to the last
     * calendar month → today. `$dryRun` counts what WOULD be staged without
     * writing anything (read-only preview).
     */
    public function fetch(?Carbon $from = null, ?Carbon $to = null, bool $dryRun = false): InboundFetchResult
    {
        $from ??= now()->subMonth()->startOfDay();
        $to ??= now()->endOfDay();

        $this->initFirebed();

        return $this->stage(
            $this->fetchDocs($from->format('d/m/Y'), $to->format('d/m/Y')),
            $dryRun,
        );
    }

    /**
     * Pull every doc filed against us in [dateFrom, dateTo] (dd/MM/yyyy),
     * following the continuationToken. Returns the RAW firebed Invoice models —
     * the movement filter is applied at staging time so the count of scanned vs
     * skipped is honest.
     *
     * @return list<Invoice>
     */
    private function fetchDocs(string $dateFrom, string $dateTo): array
    {
        $docs = [];
        $nextPartitionKey = null;
        $nextRowKey = null;

        do {
            $action = new RequestDocs;

            // '' mark + dd/MM/yyyy required by firebed's GET contract; the last
            // two args drive continuationToken pagination (same as ExpenseReconciler).
            $response = $action->handle(
                '', $dateFrom, $dateTo, null, null, null, null, $nextPartitionKey, $nextRowKey,
            );

            // Empty windows: AADE returns an empty/absent <invoicesDoc>, stored
            // as a scalar/null — the typed getter would TypeError. Read raw +
            // is_iterable, exactly like ExpenseReconciler/SalesReconciler.
            $invoicesDoc = $response->get('invoicesDoc');
            if (is_iterable($invoicesDoc)) {
                foreach ($invoicesDoc as $doc) {
                    $docs[] = $doc;
                }
            }

            $token = $response->get('continuationToken');
            $token = $token instanceof ContinuationToken ? $token : null;
            $nextPartitionKey = $token?->getNextPartitionKey();
            $nextRowKey = $token?->getNextRowKey();
        } while ($token !== null && (! empty($nextPartitionKey) || ! empty($nextRowKey)));

        return $docs;
    }

    /**
     * @param  list<Invoice>  $docs
     */
    private function stage(array $docs, bool $dryRun = false): InboundFetchResult
    {
        $scanned = 0;
        $created = 0;
        $updated = 0;
        $skipped = 0;
        $createdMarks = [];

        foreach ($docs as $doc) {
            $mark = (string) $doc->getMark();
            if ($mark === '') {
                continue;
            }

            // Read containers RAW + instanceof-guard: a self-closing
            // <invoiceHeader/> / <issuer/> is stored as a scalar '', and the
            // typed getters coerce-and-THROW on that. Same idiom as ExpenseReconciler.
            $header = $doc->get('invoiceHeader');
            $header = $header instanceof InvoiceHeader ? $header : null;
            $otherDn = $header?->getOtherDeliveryNoteHeader();
            $otherDn = $otherDn instanceof OtherDeliveryNoteHeader ? $otherDn : null;
            $type = $header?->getInvoiceType()?->value;
            $status = $this->deliveryStatusValue($doc);

            $isMovement = $status !== null
                || $otherDn !== null
                || Codes::isMovementOnlyType($type);

            if (! $isMovement) {
                $skipped++;

                continue;
            }

            $scanned++;

            $issuer = $doc->get('issuer');
            $issuer = $issuer instanceof Issuer ? $issuer : null;
            $issuerAfm = $issuer?->getVatNumber();

            $attributes = [
                'issuer_afm' => $issuerAfm,
                'issuer_name' => $issuer?->getName(),
                'supplier_id' => $this->matchSupplierId($issuerAfm),
                'invoice_type' => $type,
                'aa' => $header?->getAa(),
                'issue_date' => $this->parseDate($header?->getIssueDate()),
                'aade_delivery_status' => $status,
                'payload' => $this->docPayload($doc),
                'lifecycle' => $this->parseLifecycle($doc),
                'last_fetched_at' => now(),
            ];

            // Idempotent upsert on (company_id, mydata_mark), withTrashed so a
            // re-poll of a hidden row refreshes it instead of duplicating.
            $existing = InboundDeliveryNote::withTrashed()
                ->where('company_id', $this->tenant->getKey())
                ->where('mydata_mark', $mark)
                ->first();

            if ($existing !== null) {
                // Refresh the AADE snapshot + our supplier guess, but NEVER
                // touch our own disposition (local_state / reject_mark /
                // outcome_mark) — an operator's action is the source of truth
                // there, and the poll must not undo it.
                if (! $dryRun) {
                    $existing->forceFill($attributes)->save();
                }
                $updated++;

                continue;
            }

            if (! $dryRun) {
                try {
                    InboundDeliveryNote::create(array_merge($attributes, [
                        'company_id' => $this->tenant->getKey(),
                        'mydata_mark' => $mark,
                        'local_state' => InboundDeliveryNote::STATE_NEW,
                    ]));
                } catch (UniqueConstraintViolationException) {
                    // A concurrent run (a manual --tenant run overlapping the
                    // scheduled all-tenants sweep) won the insert on the
                    // (company_id, mydata_mark) unique. Treat it as an update:
                    // refresh the AADE snapshot without touching the disposition
                    // the winner may have set. No corruption, no lost poll.
                    InboundDeliveryNote::withTrashed()
                        ->where('company_id', $this->tenant->getKey())
                        ->where('mydata_mark', $mark)
                        ->first()?->forceFill($attributes)->save();
                    $updated++;

                    continue;
                }
            }
            $created++;
            $createdMarks[] = $mark;
        }

        return new InboundFetchResult(
            scannedDocs: $scanned,
            created: $created,
            updated: $updated,
            skippedNonMovement: $skipped,
            createdMarks: $createdMarks,
        );
    }

    /**
     * The AADE §7.1 delivery-status code, or null. Read defensively: firebed
     * casts `invoiceDeliveryStatus` to a DeliveryStatus enum, but a self-closing
     * element lands as a scalar '' — accept the enum, a numeric scalar, or null.
     */
    private function deliveryStatusValue(object $doc): ?int
    {
        $raw = $doc->get('invoiceDeliveryStatus');

        if ($raw instanceof DeliveryStatus) {
            return $raw->value;
        }

        return is_numeric($raw) ? (int) $raw : null;
    }

    /**
     * Best-effort ekdosi-side supplier match by (company_id, afm). Does NOT
     * create one — staging stays read-only w.r.t. other tables (creating a
     * supplier is the expense importer's job). Null when unmatched or afm-less.
     */
    private function matchSupplierId(?string $afm): ?int
    {
        if ($afm === null || $afm === '') {
            return null;
        }

        return Supplier::query()
            ->where('company_id', $this->tenant->getKey())
            ->where('afm', $afm)
            ->value('id');
    }

    private function parseDate(?string $date): ?string
    {
        if ($date === null || $date === '') {
            return null;
        }

        try {
            return Carbon::parse($date)->toDateString();
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * A lightweight, display-only snapshot of the deliveryLifecycle events for
     * the view timeline. Mirrors DeliveryLifecycleService::eventDetails so the
     * inbox reads the same shape the issuer side stores.
     *
     * @return list<array<string, mixed>>|null
     */
    private function parseLifecycle(object $doc): ?array
    {
        $lifecycle = $doc->get('deliveryLifecycle');
        if (! is_iterable($lifecycle)) {
            return null;
        }

        $events = [];
        foreach ($lifecycle as $event) {
            if (! $event instanceof DeliveryEvent) {
                continue;
            }

            $events[] = array_filter([
                'type' => $event->getEventType()?->value,
                'timestamp' => $event->getEventTimestamp(),
                'actor_vat' => $event->getActorVat(),
                'mark' => $event->getMark(),
                'details' => $this->eventDetails($event),
            ], static fn ($v) => $v !== null);
        }

        return $events === [] ? null : $events;
    }

    /**
     * @return array<string, mixed>|null
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

    /**
     * The full RequestedDoc <invoice> as an array snapshot ("file what the
     * operator saw"). firebed models are Arrayable via their attribute bag.
     *
     * @return array<string, mixed>
     */
    private function docPayload(object $doc): array
    {
        if (method_exists($doc, 'toArray')) {
            /** @var array<string, mixed> $arr */
            $arr = $doc->toArray();

            return $arr;
        }

        return [];
    }

    private function initFirebed(): void
    {
        FirebedCredentials::init($this->tenant, $this->mockHandler);
    }
}
