<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * OPS-12: a stable per-dispatch idempotency key on invoice_mail_log.
 *
 * SendInvoiceEmail generates one `send_key` (uuid) at dispatch and carries it
 * across retries (serialized on the job). If a hard crash (kill-9 / OOM) leaves
 * an attempt in 'sending' AFTER the transport already accepted the mail — the
 * classic at-least-once double-send window — the retry finds the same-key row
 * and skips re-sending instead of mailing the customer twice. Nullable for the
 * pre-existing rows (which never had a key).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invoice_mail_log', function (Blueprint $table): void {
            $table->uuid('send_key')->nullable()->after('trigger');
            $table->index(['send_key', 'status']);
        });
    }

    public function down(): void
    {
        Schema::table('invoice_mail_log', function (Blueprint $table): void {
            $table->dropIndex(['send_key', 'status']);
            $table->dropColumn('send_key');
        });
    }
};
