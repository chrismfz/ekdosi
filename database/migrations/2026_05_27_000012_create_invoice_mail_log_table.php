<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * PR #27: local mail send-log. One row per send ATTEMPT (not per
 * invoice — re-sends append, never overwrite). The ViewInvoice
 * infolist surfaces the latest few rows so operators can see
 * "queued → sent" or "queued → failed" without digging through queue
 * logs.
 *
 * Status lifecycle:
 *   queued    → job dispatched, not yet processed
 *   sending   → Mail::MessageSending fired (in the worker)
 *   sent      → Mail::MessageSent fired (transport accepted the mail)
 *   failed    → exception in handle() or transport rejected
 *
 * "sent" means "the SMTP server accepted the mail for delivery", NOT
 * "the customer received it". Bounce / delivery / open / click need
 * provider webhooks (SES / Postmark / Mailgun) which CLAUDE.md
 * tracks as a separate future PR.
 *
 * Foreign keys are `cascadeOnDelete` because the log is meaningless
 * once its invoice is gone — but operators should rarely hard-delete
 * invoices anyway (the IssueInvoice flow gates deletion at draft-only).
 *
 * `recipient` is the primary To: address (the customer's email at the
 * time of send). `cc_list` + `bcc_list` are JSON arrays for the full
 * audit. Storing as JSON (not normalized) because they're write-once
 * + read-display-only; no querying / filtering by recipient address.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('invoice_mail_log', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('company_id')->constrained()->cascadeOnDelete();
            $t->foreignId('invoice_id')->constrained()->cascadeOnDelete();

            // Send target snapshot. Captured at send time so the log
            // reflects what we actually attempted, even if customer
            // contact details change later.
            $t->string('recipient', 191);
            $t->json('cc_list')->nullable();
            $t->json('bcc_list')->nullable();

            // Mail identity snapshot.
            $t->string('from_address', 191)->nullable();
            $t->string('subject', 500)->nullable();

            // Trigger source — auto-dispatch from MyDataSubmitter, or
            // operator-clicked "Resend email" on ViewInvoice. Future:
            // 'webhook' when an external system asks us to re-send.
            $t->enum('trigger', ['auto', 'manual'])->default('auto');

            // Lifecycle.
            $t->enum('status', ['queued', 'sending', 'sent', 'failed'])->default('queued');
            $t->text('error_message')->nullable();
            $t->timestamp('queued_at')->useCurrent();
            $t->timestamp('sent_at')->nullable();
            $t->timestamp('failed_at')->nullable();

            // Who triggered the send (when known). Auto-dispatches have
            // no user (system); manual re-sends record the operator.
            $t->foreignId('triggered_by_user_id')->nullable()->constrained('users')->nullOnDelete();

            $t->timestamps();

            $t->index(['invoice_id', 'created_at']);
            $t->index(['status', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('invoice_mail_log');
    }
};
