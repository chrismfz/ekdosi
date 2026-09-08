<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * In-app update (Phase 2) — Phase B: rollback support.
 *
 *   kind             — 'update' (default) or 'rollback'.
 *   rollback_of_id   — for a rollback run, the update run it reverts.
 *   restore_snapshot — for a rollback run, the DB snapshot to RESTORE (the
 *                      rollback source). Distinct from `snapshot_file`, which on
 *                      any run is the fresh safety snapshot that run TOOK first.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('update_runs', function (Blueprint $t) {
            $t->string('kind')->default('update')->after('status');
            $t->foreignId('rollback_of_id')->nullable()->after('to_ref')
                ->constrained('update_runs')->nullOnDelete();
            $t->string('restore_snapshot')->nullable()->after('snapshot_file');
        });
    }

    public function down(): void
    {
        Schema::table('update_runs', function (Blueprint $t) {
            $t->dropConstrainedForeignId('rollback_of_id');
            $t->dropColumn(['kind', 'restore_snapshot']);
        });
    }
};
