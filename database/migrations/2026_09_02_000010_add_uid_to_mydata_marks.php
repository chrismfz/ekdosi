<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * PROV-003 / PROV-009: persist the provider document UID as its own column.
 *
 * A provider filing returns a distinct `invoiceUid` (the provider document
 * identifier) alongside the AADE MARK + authentication code. A.1112/2025 requires
 * it on the printed representation, and it is useful operational/forensic evidence
 * (invoice_filing MCP tool). Until now `ProviderResult::uid` was parsed but
 * dropped on persist — this gives it a home next to authentication_code.
 *
 * Nullable + additive: direct-myDATA marks leave it null; existing rows are
 * unaffected. A reconciliation/backfill can fill it later from the stored raw
 * response where present.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('mydata_marks', 'uid')) {
            return;
        }

        Schema::table('mydata_marks', function (Blueprint $t) {
            $t->string('uid', 255)->nullable()->after('authentication_code');
        });
    }

    public function down(): void
    {
        Schema::table('mydata_marks', function (Blueprint $t) {
            $t->dropColumn('uid');
        });
    }
};
