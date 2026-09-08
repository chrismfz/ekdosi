<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Change mydata_marks.mark_time from TIMESTAMP to TIME.
 *
 * The legacy MARK table splits date + time into two columns; MARK.DATE is
 * a DATE and MARK.TIME is a TIME-only value (HH:MM:SS, no date part). The
 * original new-schema migration declared `mark_time` as `$t->timestamp()`
 * which is a full datetime — feeding it the Firebird TIME value either
 * crashes on STRICT_TRANS_TABLES (the MariaDB default) or silently
 * stores 0000-00-00 00:00:00.
 *
 * This was flagged in the PR #3 code review as a latent bug; the bundled
 * sandbox .fbk doesn't have any MARK rows so it never triggered. Will
 * trigger on the first real production gbak that does (= every cutover
 * candidate). Fixing now while no data depends on the column.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('mydata_marks', function (Blueprint $t) {
            $t->time('mark_time')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('mydata_marks', function (Blueprint $t) {
            $t->timestamp('mark_time')->nullable()->change();
        });
    }
};
