<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * In-app update (Phase 2) — one row per operator-triggered application update.
 *
 * GLOBAL, not tenant-scoped: an update is a whole-app deploy (git checkout +
 * composer + migrate), so there is no company_id here. Rows are an immutable
 * audit trail of every apply/rollback; the live `output` + `phase` drive the
 * super_admin «Ενημερώσεις» page while the run is in flight.
 *
 * The updater itself runs out-of-band (the cron scheduler picks up a `queued`
 * row via `ekdosi:self-update --pending`), so the web request that creates the
 * row returns immediately and the UI just polls this table. See
 * docs/versioning-and-updates.md.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('update_runs', function (Blueprint $t) {
            $t->id();

            // queued → running → succeeded | failed | rolled_back
            $t->string('status')->default('queued')->index();
            // The current step while running (preflight/snapshot/checkout/…),
            // shown as a badge in the panel. Null until claimed.
            $t->string('phase')->nullable();
            // 'php' (portable, default) or 'script' (wrap deploy/update.sh on a VPS).
            $t->string('strategy')->default('php');

            // Where we came from / where we're going (for display + rollback).
            $t->string('from_version')->nullable();   // SemVer at trigger time
            $t->string('from_ref')->nullable();        // deployed commit sha at trigger time
            $t->string('to_version')->nullable();      // target SemVer
            $t->string('to_ref')->nullable();          // target tag/branch we check out

            // The pre-update DB snapshot (the rollback point), path under
            // storage/app/db-snapshots. Null until the snapshot step runs.
            $t->string('snapshot_file')->nullable();

            // Live captured output (redacted). Full detail also lands in
            // storage/logs/updates/<id>.log; this column is the UI mirror.
            $t->longText('output')->nullable();
            $t->text('error_message')->nullable();

            $t->foreignId('triggered_by_user_id')->nullable()->constrained('users')->nullOnDelete();

            $t->timestamp('started_at')->nullable();
            $t->timestamp('finished_at')->nullable();
            $t->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('update_runs');
    }
};
