<?php

namespace App\Services\Whmcs;

use App\Exceptions\Whmcs\WhmcsApiException;
use App\Exceptions\Whmcs\WhmcsNotConfigured;
use App\Exceptions\Whmcs\WhmcsUnreachable;
use App\Models\Company;
use App\Models\Customer;
use App\Models\PendingWhmcsInvoice;
use Filament\Notifications\Notification;
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
        // Slice 2: if the payload came from the bridge feed with routing embedded
        // (with_routing), use it — no extra resolve HTTP call. Absent (native API
        // path / old plugin) → thirdPartyDecision falls back to the resolve call.
        $embeddedRouting = is_array($whmcsInvoicePayload['third_party'] ?? null)
            ? $whmcsInvoicePayload['third_party']
            : null;
        // Don't persist the routing block inside the stored payload — it's
        // transport metadata (kept separately in third_party_resolution), and
        // dropping it keeps the snapshot identical to the native-API shape.
        unset($whmcsInvoicePayload['third_party']);
        $tp = $this->thirdPartyDecision($tenant, $invoiceId, $embeddedRouting);

        // A WHMCS "mass payment" / consolidated invoice (every line references
        // another invoice, no VAT of its own) is a payment-grouping artefact,
        // not a sale — park it HELD so neither the operator nor άμεση
        // τιμολόγηση can issue it (the source invoices are the real
        // παραστατικά). Detection reads only the payload → computed here.
        $consolidatedRefs = PendingWhmcsInvoice::detectConsolidatedRefs($whmcsInvoicePayload);

        // attempts=3: two concurrent ingests of the SAME missing (company_id,
        // whmcs_invoice_id) row resolve differently by isolation level — a
        // unique-key violation under READ COMMITTED (caught inline below) OR an
        // InnoDB deadlock under REPEATABLE READ (MariaDB's default: both txns
        // gap-lock the missing row, then the inserts deadlock). Laravel retries
        // the closure on the deadlock; on the retry the row now EXISTS, so the
        // lockForUpdate SELECT finds it and we take the clean existing-row path.
        // Belt (retry) + suspenders (inline unique-violation catch).
        $result = DB::transaction(function () use (
            $tenant, $whmcsInvoicePayload, $invoiceId, $whmcsUserId, $match, $tp, $consolidatedRefs
        ) {
            $existing = PendingWhmcsInvoice::query()
                ->where('company_id', $tenant->id)
                ->where('whmcs_invoice_id', $invoiceId)
                ->lockForUpdate()
                ->first();

            // Third-party single-contact billing overrides the standard
            // reseller match; otherwise keep the matched WHMCS client.
            $customerId = $tp['customer_id'] ?? $match->customer?->id;

            // A consolidated payment is held regardless of third-party routing —
            // it's not a billable document at all. Its hold reason wins over a
            // third-party note (and surfaces in the inbox via hold_reason).
            $isConsolidated = $consolidatedRefs !== null;
            $consolidatedReason = $isConsolidated
                ? PendingWhmcsInvoice::consolidatedPaymentReason($consolidatedRefs)
                : null;
            $createStatus = ($isConsolidated || $tp['hold'])
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
                        'notes' => $consolidatedReason ?? $tp['note'],
                        'hold_reason' => $consolidatedReason,
                    ]);

                    return new IngestionResult(row: $row, created: true, auditPreserved: false);
                } catch (QueryException $e) {
                    // Concurrent ingest race under READ COMMITTED: another worker
                    // created the same (company_id, whmcs_invoice_id) row between
                    // our lockForUpdate SELECT and this INSERT → a unique-key
                    // violation. Re-select and fall through to the refresh path.
                    // (Under MariaDB's default REPEATABLE READ the same race
                    // deadlocks instead of dup-keying; that's NOT caught here — it
                    // is handled by the transaction's attempts=3 retry, which
                    // re-runs the closure and finds the now-existing row.) Without
                    // one of the two, the QueryException escapes as a false 500.
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

            if ($existing->isAuditFrozen() || $existing->hasBeenConsolidated()) {
                // Freeze the payload/customer/status against this re-ingest; touch
                // updated_at so the inbox can show "WHMCS pinged us again about this
                // after we decided on it" as a signal. Two cases:
                //
                //  (1) status != pending_review (isAuditFrozen): the decision-time
                //      payload is the audit truth (filed → the AADE MARK references
                //      it; held/rejected → the operator's reason was captured against
                //      it).
                //  (2) an already-CONSOLIDATED mass-pay container (hasBeenConsolidated):
                //      it sits in pending_review with a SYNTHETIC merged payload (the
                //      children's real lines + ekdosi_consolidated_children). WHMCS
                //      still reports the source invoice as a raw mass-pay, so refreshing
                //      would re-detect it as consolidated, overwrite the merge back to
                //      the reference lines AND flip it to held — undoing the operator's
                //      «Ενοποίηση» so no issuable draft ever appears. The merged payload
                //      is the resolved draft-source; the only next step is «Δημιουργία
                //      Παραστατικού» (the «Ενοποίηση» action is hidden once merged), so
                //      there is nothing a re-ingest could usefully refresh here.
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
            if ($isConsolidated) {
                $update['status'] = PendingWhmcsInvoice::STATUS_HELD;
                $update['notes'] = $consolidatedReason;
                $update['hold_reason'] = $consolidatedReason;
            } elseif ($tp['hold']) {
                $update['status'] = PendingWhmcsInvoice::STATUS_HELD;
                $update['notes'] = $tp['note'];
            }
            $existing->update($update);

            return new IngestionResult(row: $existing, created: false, auditPreserved: false);
        }, 3);

        // After commit (outside the tx, so a customer-row write / notify hiccup can't
        // roll back the staging): mirror the γκρινιάρης flag onto the matched customer,
        // then ping the operators for a NEW immediate-invoice row. Order matters — the
        // mirror runs first so the bell reflects the just-synced flag. Audit-frozen
        // rows are skipped (their stored payload is stale, not the fresh griniaris).
        if (! $result->auditPreserved) {
            $this->mirrorImmediateInvoiceFlag($tenant, $match->customer, $result->row);
        }
        $this->notifyIfImmediate($tenant, $result);

        return $result;
    }

    /**
     * Database-notify the tenant's operators when a NEW «άμεση τιμολόγηση»
     * (needs_immediate_invoice) row is staged for review — the durable «bell» so
     * a paid-and-waiting row is never missed (no unattended filing needed). Only
     * on creation + pending_review + the matched customer flagged immediate;
     * re-ingests (updates) and non-immediate rows are silent. Best-effort: a
     * notification failure must never break ingestion.
     */
    private function notifyIfImmediate(Company $tenant, IngestionResult $result): void
    {
        // Fully best-effort: the customer/users lazy-loads AND the send are all
        // inside the try, so nothing here (a slow DB, a notify hiccup) can bubble
        // out of an already-committed ingest.
        try {
            $row = $result->row;
            if (! $result->created
                || $row->status !== PendingWhmcsInvoice::STATUS_PENDING_REVIEW
                // An UNPAID row (whmcs:fetch-unpaid, «τιμολόγιο πριν την πληρωμή») is issued
                // επί πιστώσει MANUALLY — never «άμεσα». Firing the immediate bell for it (when
                // the customer happens to carry both flags) contradicts the actual workflow.
                || $row->whmcsIsUnpaid()
                || ! $row->customer?->needs_immediate_invoice) {
                return;
            }

            $recipients = $tenant->users;
            if ($recipients->isEmpty()) {
                return;
            }

            Notification::make()
                ->title(ImmediateInvoiceBell::TITLE)
                ->body("WHMCS #{$row->whmcs_invoice_id} — {$row->customer?->name} ζητά άμεση τιμολόγηση.")
                ->icon('heroicon-o-bolt')
                ->color('danger')
                // Structured tag so the bell can be auto-cleared once the WHMCS
                // row is handled (ImmediateInvoiceBell::resolve, from the observer).
                ->viewData(ImmediateInvoiceBell::tag($tenant->id, (int) $row->whmcs_invoice_id))
                ->sendToDatabase($recipients);
        } catch (\Throwable $e) {
            Log::warning('Immediate-invoice notification failed (ingestion unaffected).', [
                'company_id' => $tenant->id,
                'pending_id' => $result->row->id ?? null,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Mirror the WHMCS «γκρινιάρης» flag onto the matched ekdosi customer's
     * needs_immediate_invoice — WHMCS is the source of truth for this flag on
     * WHMCS-linked customers (operator decision, 2026-09). Runs POST-COMMIT on every
     * non-frozen ingest, so a WHMCS toggle a month later propagates — unlike the
     * create-time seed in WhmcsCustomerCreator, which only fires for a brand-new
     * customer. Post-commit + best-effort (like notifyIfImmediate): a customer-row
     * write or activity-log insert must never roll back the invoice staging.
     *
     * Guards:
     *  - Targets $match->customer — the PRIMARY WHMCS client's customer (whose
     *    customfields carry the griniaris value), NEVER a third-party end-customer
     *    (the row's own customer_id may be a routed end-customer).
     *  - intent === null → leave the flag untouched. That covers BOTH «tenant hasn't
     *    mapped griniaris» AND «couldn't read the client's customfields» (a transient
     *    WHMCS lookup failure) — see PendingWhmcsInvoice::wantsImmediateInvoice. Only
     *    a readable, mapped, genuinely-unchecked field flips it OFF.
     *  - Writes only on a real change: no needless updated_at, and a genuine flip is
     *    audited as a «Σύστημα» activity-log entry (needs_immediate_invoice is logged).
     *
     * $result->row's stored payload is the FRESH, committed one (create stored it; the
     * pre-filing branch updated it), so wantsImmediateInvoice() reads the current
     * griniaris. $match->customer is loaded before the tx; a concurrent flag change is
     * tolerated (idempotent — the next ingest re-syncs). setRelation avoids a company
     * re-query. Frozen rows are excluded by the caller (stale stored payload).
     */
    private function mirrorImmediateInvoiceFlag(Company $tenant, ?Customer $customer, PendingWhmcsInvoice $row): void
    {
        if ($customer === null || $customer->company_id !== $tenant->id) {
            return;
        }

        try {
            $row->setRelation('company', $tenant);
            $intent = $row->wantsImmediateInvoice();
            if ($intent === null) {
                return;   // unmapped tenant OR unreadable customfields — WHMCS isn't authoritative here
            }

            if ((bool) $customer->needs_immediate_invoice === $intent) {
                return;   // already in sync — no write, no activity-log noise
            }

            $customer->needs_immediate_invoice = $intent;
            $customer->save();
        } catch (\Throwable $e) {
            Log::warning('Immediate-invoice flag mirror failed (ingestion unaffected).', [
                'company_id' => $tenant->id,
                'customer_id' => $customer->id,
                'error' => $e->getMessage(),
            ]);
        }
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
    private function thirdPartyDecision(Company $tenant, int $invoiceId, ?array $embeddedRouting = null): array
    {
        $noop = ['state' => null, 'customer_id' => null, 'resolution' => null, 'hold' => false, 'note' => null];

        if (! $tenant->whmcs_third_party_enabled) {
            return $noop;
        }

        if (is_array($embeddedRouting)) {
            // Slice 2: the bridge feed (op=invoices, with_routing) already carries
            // the routing — build the resolution from it, no separate HTTP call.
            $resolution = ThirdPartyResolution::fromBridgeResponse($embeddedRouting);
        } else {
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
                // A guided refusal (e.g. «ΔΙΑΓΡΑΜΜΕΝΟΣ πελάτης … επανέφερέ τον») must reach
                // the operator on the held row, not only laravel.log.
                'note' => 'Παραστατικά σε τρίτους: αποτυχία δημιουργίας πελάτη-δικαιούχου — έλεγξε χειροκίνητα.'
                    .($e instanceof \RuntimeException ? ' '.$e->getMessage() : ''),
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
