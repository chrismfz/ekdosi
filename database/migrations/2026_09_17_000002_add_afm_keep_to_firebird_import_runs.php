<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `firebird_import_runs.afm_keep` — the operator's answer to «two legacy
 * customers share one ΑΦΜ: which one keeps it?», as typed in the import form
 * (CUST_IDs, comma-separated). The job hands each one to `migrate:firebird`
 * as `--afm-keep=`.
 *
 * Without it a panel-only operator had no way out of an in-source duplicate:
 * the run refused, and the only remedy the message could offer was the CLI.
 * Stored on the run (not just passed to the job) so a past import shows WHICH
 * identity decision it was made under — the same reason `fb_host`/`fb_user`
 * are snapshotted here.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('firebird_import_runs', 'afm_keep')) {
            return;
        }

        Schema::table('firebird_import_runs', function (Blueprint $t): void {
            $t->string('afm_keep', 255)->nullable()->after('fb_database');
        });
    }

    public function down(): void
    {
        Schema::table('firebird_import_runs', fn (Blueprint $t) => $t->dropColumn('afm_keep'));
    }
};
