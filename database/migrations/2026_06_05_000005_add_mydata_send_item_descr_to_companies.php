<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Opt-in: also transmit the per-line description (<itemDescr>) to myDATA.
 *
 * myDATA does NOT require it (it carries tax aggregates), so we omit it by
 * default — byte-identical to the sandbox-validated payload. A tenant can flip
 * this on to surface the line text on the AADE QR/verification page +
 * RequestTransmittedDocs (so reconciliation / MARK-detail show it, not «—»).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $table): void {
            $table->boolean('mydata_send_item_descr')->default(false)->after('mydata_mode');
        });
    }

    public function down(): void
    {
        Schema::table('companies', function (Blueprint $table): void {
            $table->dropColumn('mydata_send_item_descr');
        });
    }
};
