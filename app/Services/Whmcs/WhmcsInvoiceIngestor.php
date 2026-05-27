<?php

namespace App\Services\Whmcs;

use App\Models\Company;
use App\Models\PendingWhmcsInvoice;
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

            // Run the matcher against the rich payload's client fields.
            // GetInvoice carries `userid` (and sometimes embeds the
            // client block); we feed both to the matcher.
            $whmcsUserId = (int) ($whmcsInvoicePayload['userid'] ?? 0);
            $match = $this->matcher->match($tenant, [
                'id'          => $whmcsUserId,
                'userid'      => $whmcsUserId,
                'email'       => $whmcsInvoicePayload['email'] ?? null,
                'firstname'   => $whmcsInvoicePayload['firstname'] ?? null,
                'lastname'    => $whmcsInvoicePayload['lastname'] ?? null,
                'companyname' => $whmcsInvoicePayload['companyname'] ?? null,
            ]);

            if ($existing === null) {
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
            }

            if ($existing->isAuditFrozen()) {
                // Already filed - touch updated_at so the inbox can
                // show "WHMCS pinged us again about this after filing"
                // but DO NOT overwrite payload / customer_id /
                // match_reason. The post-filing audit trail is frozen.
                $existing->touch();
                return new IngestionResult(row: $existing, created: false, auditPreserved: true);
            }

            // Pre-filing row: refresh the snapshot from the latest
            // WHMCS payload, re-run the matcher (a customer might
            // have been linked since the first ingest). Status,
            // notes, rejected_reason stay intact - those are operator
            // decisions, not WHMCS-driven.
            $existing->update([
                'whmcs_userid' => $whmcsUserId ?: null,
                'customer_id'  => $match->customer?->id,
                'payload'      => $whmcsInvoicePayload,
                'match_reason' => $match->reason,
            ]);

            return new IngestionResult(row: $existing, created: false, auditPreserved: false);
        });
    }
}
