<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * PR #31 (WHMCS bridge - Stage B-1): staging table for incoming WHMCS
 * invoices awaiting operator review.
 *
 * Per CLAUDE.md decision (2026-05-27): the WHMCS bridge is
 * OPERATOR-GATED, not auto-issuing. Every paid+unfiled WHMCS invoice
 * lands here as a row in status='pending_review'. Stage B-2's inbox
 * UI is the only path that promotes a row to AADE-filed; no other
 * code in this PR (or in Stage B-1's webhook / pull command) touches
 * AADE.
 *
 * Two ingress paths, both end up here:
 *   1. Push: WHMCS-side plugin (Stage B-3) POSTs to
 *      /webhooks/whmcs/{slug}/invoice-paid with an HMAC signature.
 *   2. Pull: `whmcs:fetch-pending --tenant=SLUG` artisan command
 *      polls the WHMCS API.
 *
 * Idempotency key: (company_id, whmcs_invoice_id). A second
 * push / pull for the same invoice id updates the existing row
 * (refreshes payload, re-runs the customer match) UNLESS the row
 * is already in status='filed' - in that case the row is frozen
 * (the payload at filing time is the audit-truth and must not be
 * mutated by a later WHMCS-side edit).
 *
 * Status lifecycle (state transitions enforced by the future inbox UI):
 *   pending_review  ->  filed | rejected | held
 *   held            ->  pending_review (operator lifts the hold)
 *   rejected        ->  pending_review (operator re-stages)
 *   filed           ->  (terminal; immutable except for the WHMCS
 *                        write-back columns)
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pending_whmcs_invoices', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('company_id')->constrained()->cascadeOnDelete();

            // The WHMCS-side invoice id - the natural idempotency key.
            // Unique per (company_id, whmcs_invoice_id) because two
            // tenants could each see "invoice 12345" from their own
            // separate WHMCS installs.
            $t->unsignedBigInteger('whmcs_invoice_id');

            // The WHMCS-side client id (tblclients.id). Stored even
            // when customer_id is null (= no ekdosi-side match) so the
            // future inbox UI can offer "create new customer from this
            // WHMCS client" without needing another API call.
            $t->unsignedBigInteger('whmcs_userid')->nullable();

            // Suggested ekdosi customer (matcher's best guess). Nullable
            // because unmatched rows still land here - operator picks
            // the customer in the inbox UI before filing. nullOnDelete
            // so deleting a customer doesn't cascade-nuke staged rows;
            // operator can re-pick.
            $t->foreignId('customer_id')->nullable()->constrained()->nullOnDelete();

            // Full GetInvoice response from WHMCS, snapshotted at
            // ingest time. This is what Stage B-2's "File at AADE"
            // action reads to build Invoice + InvoiceLine rows.
            // Stored as JSON, not normalised, because the WHMCS API
            // response shape varies across WHMCS versions and we want
            // to file what the operator saw, not a transformed view.
            $t->json('payload');

            // Matcher's reason: linked | afm | email | name | unmatched.
            // Drives the confidence badge in the inbox UI.
            $t->string('match_reason', 20);

            // Lifecycle status. See class docblock for transitions.
            // Enum-as-string (not a real enum column) so the set is
            // extensible without an ALTER on a busy table.
            $t->string('status', 20)->default('pending_review');

            // Operator-visible notes - reasons captured at reject/hold
            // time, or operator-added context during review.
            $t->text('notes')->nullable();
            $t->string('rejected_reason', 200)->nullable();

            // Filing audit (populated by Stage B-2's File-at-AADE action;
            // null until then). filed_at + filed_by_user_id tell the
            // inbox who clicked Submit; mydata_mark mirrors
            // invoices.mydata_mark for cross-reference without a JOIN.
            $t->timestamp('filed_at')->nullable();
            $t->foreignId('filed_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $t->string('mydata_mark', 30)->nullable();

            $t->timestamps();

            // Idempotency key. Composite UNIQUE rather than two
            // separate indexes because every lookup needs both halves.
            $t->unique(['company_id', 'whmcs_invoice_id'], 'pwi_company_invoice_unique');

            // Inbox filter index: ListPendingWhmcsInvoices in Stage B-2
            // filters by (tenant, status='pending_review') ordered by
            // created_at desc. This index serves that query in one
            // seek.
            $t->index(['company_id', 'status', 'created_at'], 'pwi_inbox_filter_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pending_whmcs_invoices');
    }
};
