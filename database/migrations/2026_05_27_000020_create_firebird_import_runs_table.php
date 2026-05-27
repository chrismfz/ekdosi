<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * PR #30 — Firebird Import UI.
 *
 * Each row tracks one operator-initiated import attempt: a `.fbk`
 * uploaded via the Filament panel, then restored with `gbak -r` to a
 * temporary `.fdb`, then drained by `MigrateFromFirebird` into the
 * tenant's tables.
 *
 * Runs are immutable once created — operators can re-import (creates
 * a NEW row) but never edit history. The row is the audit trail of
 * "who imported what file, when, with what result".
 *
 * Columns:
 *   file_sha256       — used for "this exact backup was already
 *                       imported on YYYY-MM-DD by Alice" dedup hint
 *                       (not a hard block — re-importing the same
 *                       backup is safe by design via PR #29's upsert)
 *   uploaded_path     — relative path on disk under storage/app/
 *                       (private, never public). Cleaned up after
 *                       successful import; retained on failure for
 *                       debugging.
 *   status            — uploaded | restoring | importing | completed
 *                       | failed
 *   counts_json       — per-table row counts produced by the import,
 *                       e.g. {"customers": 1240, "invoices": 8500}.
 *                       Filled when the job completes successfully.
 *   error_message     — populated when status='failed', with the
 *                       failure point + sanitized exception message.
 *
 * Why no soft-deletes: import history is legal-ish (auditable record
 * of data provenance). Operators who want to "delete a bad run" can
 * note it in the row's status; we never hide rows.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('firebird_import_runs', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('company_id')
                ->constrained('companies')
                ->cascadeOnDelete();
            $t->foreignId('uploaded_by_user_id')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();

            $t->string('file_name', 255);
            $t->unsignedBigInteger('file_size');
            $t->string('file_sha256', 64)->index();
            $t->string('uploaded_path', 500)->nullable();

            $t->string('status', 20)->default('uploaded');
            $t->timestamp('started_at')->nullable();
            $t->timestamp('finished_at')->nullable();

            $t->json('counts_json')->nullable();
            $t->text('error_message')->nullable();
            $t->string('failed_step', 50)->nullable();  // 'gbak' | 'connect' | 'migrate'

            // Firebird connection params snapshot — captured at job
            // dispatch time. Lets the operator see exactly what
            // credentials a past run used (useful when troubleshooting
            // "why did the day-7 import touch a different DB").
            $t->string('fb_host', 100)->default('127.0.0.1');
            $t->string('fb_user', 100)->default('SYSDBA');
            // password NOT stored — flows through to the job in-memory
            // only; operators re-enter on each import.

            $t->timestamps();

            $t->index(['company_id', 'status', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('firebird_import_runs');
    }
};
