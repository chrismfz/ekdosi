<?php

namespace App\Services\Whmcs;

use App\Models\Company;
use App\Models\PendingWhmcsInvoice;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
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
    ) {
    }

    /**
     * @param array<string, mixed> $whmcsInvoicePayload The full
     *        GetInvoice response from WHMCS (rich shape with line
     *        items, userid, customer identity). NOT the GetInvoices
     *        list shape - the pull command must call getInvoice($id)
     *        per row before invoking the ingestor.
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

        return DB::transaction(function () use ($tenant, $whmcsInvoicePayload, $invoiceId) {
            $existing = PendingWhmcsInvoice::query()
                ->where('company_id', $tenant->id)
                ->where('whmcs_invoice_id', $invoiceId)
                ->lockForUpdate()
                ->first();

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

            if ($existing === null) {
                try {
                    $row = PendingWhmcsInvoice::create([
                        'company_id'       => $tenant->id,
                        'whmcs_invoice_id' => $invoiceId,
                        'whmcs_userid'     => $whmcsUserId ?: null,
                        'customer_id'      => $match->customer?->id,
                        'payload'          => $whmcsInvoicePayload,
                        'match_reason'     => $match->reason,
                        'status'           => PendingWhmcsInvoice::STATUS_PENDING_REVIEW,
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
            // first ingest). Status, notes, rejected_reason stay
            // intact - those are operator decisions, not WHMCS-driven.
            $existing->update([
                'whmcs_userid' => $whmcsUserId ?: null,
                'customer_id'  => $match->customer?->id,
                'payload'      => $whmcsInvoicePayload,
                'match_reason' => $match->reason,
            ]);

            return new IngestionResult(row: $existing, created: false, auditPreserved: false);
        });
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
