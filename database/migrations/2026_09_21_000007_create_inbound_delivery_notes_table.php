<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Slice 4a — «Εισερχόμενα Διακίνησης» staging table (recipient inbound-movement
 * inbox). See docs/delivery-inbound-design.md.
 *
 * The recipient mirror of the issuer delivery lifecycle: the ψηφιακή-διακίνηση
 * documents OTHERS filed against US (goods we are receiving), discovered by
 * `RequestDocs` (the same feed ExpenseReconciler reads) and staged here for an
 * operator to reject / refresh / (later) confirm.
 *
 * DELIBERATELY a dedicated staging table (the twin of `pending_whmcs_invoices`),
 * NOT the issuer `delivery_marks`/`delivery_note_events` polymorphic audit: these
 * are OTHER parties' legal documents — we hold no issued MARK for them and their
 * lifecycle state lives at AADE, not in our issue flow. Keeping them out of the
 * audit preserves that audit's one-owner/one-issued-MARK invariant.
 *
 * Idempotency key: (company_id, mydata_mark) — a re-poll upserts the JSON
 * snapshot + the parsed AADE status/lifecycle without duplicating the row. Two
 * tenants could each see the same numeric MARK from their own separate feeds, so
 * the unique is per-company.
 *
 * READ-ONLY staging: the fetch command only stages. Reject/confirm (the AADE
 * mutations) are the operator-gated Filament actions (Slice 4b/4c), never the poll.
 *
 * softDeletes: an operator "hide" leaves the row for audit; a re-poll of a
 * soft-deleted MARK is handled withTrashed by the fetcher (never a duplicate).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inbound_delivery_notes', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('company_id')->constrained()->cascadeOnDelete();

            // The AADE MARK of the OTHER party's document — the idempotency key.
            // String (not integer) to match how `mydata_mark` is stored elsewhere
            // (mydata_marks, expenses) and to never lose a leading digit.
            $t->string('mydata_mark');

            // The counterparty = the ISSUER (sender of the goods). We are the
            // <counterpart> on their document.
            $t->string('issuer_afm')->nullable();
            $t->string('issuer_name')->nullable();

            // Best-effort ekdosi-side supplier match (by afm). Nullable — an
            // unmatched inbound doc still stages; nullOnDelete so removing a
            // supplier never cascade-nukes staged audit rows.
            $t->foreignId('supplier_id')->nullable()->constrained()->nullOnDelete();

            // §8.1 type of the received doc (e.g. '9.3' pure ΔΑ, '1.1' combined ΤΔΑ).
            $t->string('invoice_type')->nullable();
            $t->string('aa')->nullable();               // issuer's series/AA (display only)
            $t->date('issue_date')->nullable();

            // Normally NULL: the counterpart RequestDocs feed does NOT return the
            // issuer's qrCodeUrl (empirically confirmed, docs/delivery-two-party-
            // sandbox.md). Filled by a physical-QR scan when confirming (Slice 4c).
            $t->string('qr_code_url')->nullable();

            // §7.1 InvoiceDeliveryStatus code (firebed DeliveryStatus enum) — the
            // AADE truth, refreshed on «Έλεγχος κατάστασης».
            $t->unsignedTinyInteger('aade_delivery_status')->nullable();

            // OUR disposition, orthogonal to the AADE status (the same two-status
            // discipline as invoices.local_status × mydata_state):
            //   new | acknowledged | rejected | confirmed | cancelled_by_issuer
            // Enum-as-string so the set stays extensible without an ALTER.
            $t->string('local_state', 30)->default('new');

            // MARKs of OUR recipient actions, populated by the action that
            // creates them (never left write-only — see BACKLOG 1(b) lesson).
            $t->string('reject_mark')->nullable();      // our RejectDeliveryNote MARK
            $t->string('outcome_mark')->nullable();     // our ConfirmDeliveryOutcome MARK (4c)

            // Full RequestedDoc <invoice> snapshot (file what the operator saw).
            $t->json('payload');
            // Parsed deliveryLifecycle events, for the view timeline (display only).
            $t->json('lifecycle')->nullable();

            $t->timestamp('last_fetched_at')->nullable();

            $t->timestamps();
            $t->softDeletes();

            $t->unique(['company_id', 'mydata_mark']);
            $t->index(['company_id', 'local_state']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inbound_delivery_notes');
    }
};
