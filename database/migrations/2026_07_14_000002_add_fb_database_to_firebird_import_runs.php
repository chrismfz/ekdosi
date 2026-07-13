<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Live-connection Firebird import: the remote database path (the `.fdb` on the
 * legacy box) for a run that connects DIRECTLY to a live Firebird by
 * host/credentials instead of uploading a `.fbk`/`.fdb`. A run is "live" when
 * `uploaded_path` is null and `fb_database` is set. The password is never
 * stored (in-memory to the job only), same as the file path.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('firebird_import_runs', function (Blueprint $t) {
            $t->string('fb_database')->nullable()->after('fb_user');
        });
    }

    public function down(): void
    {
        Schema::table('firebird_import_runs', function (Blueprint $t) {
            $t->dropColumn('fb_database');
        });
    }
};
