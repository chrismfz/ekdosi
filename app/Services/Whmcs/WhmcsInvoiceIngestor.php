<?php

namespace App\Services\Whmcs;

use App\Exceptions\Whmcs\WhmcsApiException;
use App\Exceptions\Whmcs\WhmcsNotConfigured;
use App\Exceptions\Whmcs\WhmcsUnreachable;
use App\Models\Company;
use App\Models\PendingWhmcsInvoice;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;

/**
 * PR #31 (Stage B-1): turn a WHMCS GetInvoice payload + tenant into a
 * row in pending_whmcs_invoices, idempotently.
 *
 * Idempotency contract:
 *   - Keyed on (company_id, whmcs_invoice_id).
 *   - Second call for the same key UPDATES the existing row:
 *     payload, customer_id (re-runs the matcher), match_reason are
 *     refreshed; status / filed_at / filed_by_user_id / mydata_mark
 *     are preserved.
 *   - EXCEPT: if the existing row is in status='filed', the payload
 *     is NOT refreshed. The payload-at-filing-time is the audit truth
 *     for what we sent to AADE; a later WHMCS-side edit must not
 *     retroactively rewrite that history. customer_id and match_reason
 *     are also frozen on filed rows for the same reason.
 *
 * Nothing in this class talks to AADE. Nothing returns a Symfony
 * HttpResponse. Callers (artisan command, webhook controller) drive
 * the wire layer.
 *
 * Transactional: the upsert + matcher invocation run inside a single
 * DB transaction so a midway exception leaves no half-written row.
 */
class WhmcsInvoiceIngestor
{
    public function __construct(
        private WhmcsCustomerMatcher $matcher,
        private WhmcsBridgeClientFactory $bridgeFactory,
        private ContactCustomerResolver $contactResolver,
    ) {}

    /**
     * @param  array<string, mixed>  $whmcsInvoicePayload  The full
     *                                                     GetInvoice response from WHMCS (rich shape with line
     *                                                     items, userid, customer identity). NOT the GetInvoices
     *                                                     list shape - the pull command must call getInvoice($id)
     *                                                     per row before invoking the ingestor.
     */
    public function ingest(Company $tenant, array $whmcsInvoicePayload): IngestionResult
    {
        $invoiceId = (int) ($whmcsInvoicePayload['invoiceid']
            ?? $whmcsInvoicePayload['id']
            ?? 0);

        if ($invoiceId <= 0) {
            throw new InvalidArgumentException(
                'WHMCS invoice payload missing invoiceid/id - cannot stage.'
            );
        }

        // Pass the FULL payload to the matcher. Critically this
        // includes `customfields` (when present - see
        // WhmcsClient::getInvoiceWithClient which enriches the
        // GetInvoice response with the linked client's
        // customfields block). Without `customfields` the
        // matcher's strategy #2 (AFM-by-customfield-id) is dead
        // for every ingest; the cherry-picked array shape that
        // existed here previously stripped it silently. Extra
        // keys the matcher doesn't read are ignored - safe.
        $whmcsUserId = (int) ($whmcsInvoicePayload['userid'] ?? 0);
        $match = $this->matcher->match($tenant, $whmcsInvoicePayload);

        // T-1b: third-party-invoicing resolution. Computed OUTSIDE the row
        // transaction because it does an HTTP call to the bridge + may
        // find-or-create the end-customer Customer — neither should run while
        // holding a row lock. Off (kill-switch / not configured / resolve.php
        // not deployed) → a no-op decision and today's behaviour is unchanged.
        $tp = $this->thirdPartyDecision($tenant, $invoiceId);

        return DB::transaction(function () use (
            $tenant, $whmcsInvoicePayload, $invoiceId, $whmcsUserId, $match, $tp
        ) {
            $existing = PendingWhmcsInvoice::query()
                ->where('company_id', $tenant->id)
                ->where('whmcs_invoice_id', $invoiceId)
                ->lockForUpdate()
                ->first();

            // Third-party single-contact billing overrides the standard
            // reseller match; otherwise keep the matched WHMCS client.
            $customerId = $tp['customer_id'] ?? $match->customer?->id;
            $createStatus = $tp['hold']
                ? PendingWhmcsInvoice::STATUS_HELD
                : PendingWhmcsInvoice::STATUS_PENDING_REVIEW;

            if ($existing === null) {
                try {
                    $row = PendingWhmcsInvoice::create([
                        'company_id' => $tenant->id,
                        'source' => PendingWhmcsInvoice::SOURCE_WHMCS,
                        'whmcs_invoice_id' => $invoiceId,
                        'whmcs_userid' => $whmcsUserId ?: null,
                        'customer_id' => $customerId,
                        'payload' => $whmcsInvoicePayload,
                        'match_reason' => $match->reason,
                        'third_party_state' => $tp['state'],
                        'third_party_resolution' => $tp['resolution'],
                        'status' => $createStatus,
                        'notes' => $tp['note'],
                    ]);

                    return new IngestionResult(row: $row, created: true, auditPreserved: false);
                } catch (QueryException $e) {
                    // Concurrent ingest race: between our lockForUpdate
                    // SELECT (which doesn't gap-lock under MariaDB
                    // READ COMMITTED for missing rows) and this INSERT,
                    // another worker created the same (company_id,
                    // whmcs_invoice_id) row. Re-select and fall through
                    // to the refresh path. Without this catch the
                    // QueryException escapes to the webhook controller
                    // as a 500 - WHMCS-side retries succeed on the next
                    // tick but the false 500 in logs is indistinguishable
                    // from a real bug.
                    if (! $this->isUniqueConstraintViolation($e)) {
                        throw $e;
                    }
                    $existing = PendingWhmcsInvoice::query()
                        ->where('company_id', $tenant->id)
                        ->where('whmcs_invoice_id', $invoiceId)
                        ->lockForUpdate()
                        ->first();
                    // Defensive: existing is null only if the row was
                    // ALSO deleted between our INSERT race and the
                    // re-SELECT. Bubble the original exception then -
                    // some other ordering bug is at play.
                    if ($existing === null) {
                        throw $e;
                    }
                    // Fall through to the existing-row branches below.
                }
            }

            if ($existing->isAuditFrozen()) {
                // status != pending_review - touch updated_at so the
                // inbox can show "WHMCS pinged us again about this
                // after we decided on it" as a signal, but DO NOT
                // overwrite payload / customer_id / match_reason.
                // The decision-time payload is the audit truth.
                $existing->touch();

                return new IngestionResult(row: $existing, created: false, auditPreserved: true);
            }

            // Pre-filing (status=pending_review) row: refresh the
            // snapshot from the latest WHMCS payload, re-run the
            // matcher (a customer might have been linked since the
            // first ingest) and re-evaluate third-party routing.
            // rejected_reason stays intact (operator decision); status
            // is forced to held only when re-evaluation says multi-party /
            // unresolvable so a newly-mixed invoice can't slip through to
            // filing.
            $update = [
                'whmcs_userid' => $whmcsUserId ?: null,
                'customer_id' => $customerId,
                'payload' => $whmcsInvoicePayload,
                'match_reason' => $match->reason,
                'third_party_state' => $tp['state'],
                'third_party_resolution' => $tp['resolution'],
            ];
            if ($tp['hold']) {
                $update['status'] = PendingWhmcsInvoice::STATUS_HELD;
                $update['notes'] = $tp['note'];
            }
            $existing->update($update);

            return new IngestionResult(row: $existing, created: false, auditPreserved: false);
        });
    }

    /**
     * Re-run third-party resolution for an ALREADY-staged pending row and
     * refresh only its third-party columns. For rows ingested while
     * whmcs_third_party_enabled was OFF (third_party_state stayed null): after
     * the flag is turned on + resolve.php deployed, this fills in the «Τρίτος»
     * picture WITHOUT waiting for a fresh WHMCS push.
     *
     * Deliberately narrow — touches ONLY third_party_state /
     * third_party_resolution. It does NOT change status, customer_id, or the
     * invoice link: a row the operator has already acted on keeps its
     * lifecycle untouched; we only surface the routing info. Returns the
     * resolved state (or the row's existing state unchanged when the feature
     * is off / bridge unreachable — a no-op, same degradation as ingest()).
     */
    public function reResolveThirdParty(Company $tenant, PendingWhmcsInvoice $row): ?string
    {
        $decision = $this->thirdPartyDecision($tenant, $row->whmcs_invoice_id);

        // No-op (feature off, bridge not deployed/unreachable) returns an
        // all-null decision — don't wipe an existing resolution back to null.
        if ($decision['state'] === null && $decision['resolution'] === null) {
            return $row->third_party_state;
        }

        $row->forceFill([
            'third_party_state' => $decision['state'],
            'third_party_resolution' => $decision['resolution'],
        ])->save();

        return $decision['state'];
    }

    /**
     * T-1b: decide how third-party invoicing affects this ingest.
     *
     * Returns a decision array:
     *   state       => null | 'none' | 'single' | 'multi'
     *   customer_id => ?int  (set only for a resolved single third party)
     *   resolution  => ?array (the resolve.php snapshot, for audit + inbox)
     *   hold        => bool  (park as held — multi-party or unresolvable)
     *   note        => ?string (operator-facing reason when held)
     *
     * Degrades to a no-op (all-null, hold=false) whenever the feature is off,
     * the bridge isn't configured, resolve.php isn't deployed yet, or the
     * bridge is unreachable — so enabling the flag before deploying the
     * endpoint can't break ingestion; it just bills the WHMCS client as today.
     *
     * @return array{state: ?string, customer_id: ?int, resolution: ?array, hold: bool, note: ?string}
     */
    private function thirdPartyDecision(Company $tenant, int $invoiceId): array
    {
        $noop = ['state' => null, 'customer_id' => null, 'resolution' => null, 'hold' => false, 'note' => null];

        if (! $tenant->whmcs_third_party_enabled) {
            return $noop;
        }

        try {
            $resolution = $this->bridgeFactory->for($tenant)->resolveThirdParty($invoiceId);
        } catch (WhmcsNotConfigured|WhmcsUnreachable|WhmcsApiException $e) {
            // Bridge not configured / resolve.php not deployed / unreachable.
            // Non-fatal: fall back to today's behaviour.
            Log::info('WHMCS third-party resolve skipped — falling back to client billing.', [
                'company_id' => $tenant->id,
                'whmcs_invoice_id' => $invoiceId,
                'reason' => $e->getMessage(),
            ]);

            return $noop;
        }

        $snapshot = $resolution->toArray();

        if (! $resolution->hasAnyRouting()) {
            return ['state' => PendingWhmcsInvoice::TP_NONE, 'customer_id' => null, 'resolution' => $snapshot, 'hold' => false, 'note' => null];
        }

        if ($resolution->isMultiParty()) {
            return [
                'state' => PendingWhmcsInvoice::TP_MULTI,
                'customer_id' => null,
                'resolution' => $snapshot,
                'hold' => true,
                'note' => 'Παραστατικά σε τρίτους: πολλαπλοί δικαιούχοι σε ένα τιμολόγιο — χρειάζεται διαχωρισμός από τον χειριστή.',
            ];
        }

        $contact = $resolution->singleContact();
        if ($contact === null) {
            // Routed but not a clean single party (defensive — isMultiParty
            // should already have caught mixed reseller+contact). Park it.
            return [
                'state' => PendingWhmcsInvoice::TP_MULTI,
                'customer_id' => null,
                'resolution' => $snapshot,
                'hold' => true,
                'note' => 'Παραστατικά σε τρίτους: ασαφής δρομολόγηση — έλεγξε χειροκίνητα.',
            ];
        }

        try {
            $customer = $this->contactResolver->resolve($tenant, $contact);
        } catch (\Throwable $e) {
            // Materialising the end-customer failed (e.g. a malformed contact
            // row, a DB constraint). The "can't break ingestion" guarantee
            // covers THIS too: never let one bad contact 500 the webhook and
            // wedge an otherwise-valid invoice out of the inbox. Park it held
            // for the operator rather than silently billing the reseller.
            Log::warning('WHMCS third-party contact could not be materialised — parking held.', [
                'company_id' => $tenant->id,
                'whmcs_invoice_id' => $invoiceId,
                'error' => $e->getMessage(),
            ]);

            return [
                'state' => PendingWhmcsInvoice::TP_SINGLE,
                'customer_id' => null,
                'resolution' => $snapshot,
                'hold' => true,
                'note' => 'Παραστατικά σε τρίτους: αποτυχία δημιουργίας πελάτη-δικαιούχου — έλεγξε χειροκίνητα.',
            ];
        }
        if ($customer === null) {
            // The third party has no ΑΦΜ — can't bill a B2B invoice safely.
            return [
                'state' => PendingWhmcsInvoice::TP_SINGLE,
                'customer_id' => null,
                'resolution' => $snapshot,
                'hold' => true,
                'note' => 'Παραστατικά σε τρίτους: ο δικαιούχος δεν έχει ΑΦΜ — έλεγξε χειροκίνητα.',
            ];
        }

        return [
            'state' => PendingWhmcsInvoice::TP_SINGLE,
            'customer_id' => $customer->id,
            'resolution' => $snapshot,
            'hold' => false,
            'note' => null,
        ];
    }

    /**
     * MariaDB and SQLite report unique violations with different
     * SQLSTATE / driver codes. The Laravel-portable check is the
     * SQLSTATE class 23 (integrity constraint violation), narrowed
     * by message inspection for the unique-key cases.
     */
    private function isUniqueConstraintViolation(QueryException $e): bool
    {
        // MariaDB: SQLSTATE 23000, driver code 1062, message contains
        // "Duplicate entry".
        // SQLite:  SQLSTATE 23000, driver code 19, message contains
        // "UNIQUE constraint failed".
        if ($e->getCode() !== '23000') {
            return false;
        }
        $msg = $e->getMessage();

        return str_contains($msg, 'Duplicate entry')
            || str_contains($msg, 'UNIQUE constraint failed');
    }
}
