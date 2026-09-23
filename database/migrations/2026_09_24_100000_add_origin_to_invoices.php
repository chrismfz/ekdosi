<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `invoices.origin` — where a document NOT issued by this app came from
 * (NULL = issued here). First value: 'mydata_orphan', a sale imported from the
 * myDATA orphans (filed elsewhere / local record lost). Such a document is
 * history, not a new issue: no «Νέο υπόλοιπο» snapshot of today's balance, no
 * automatic payment reminders — like an ETL row (legacy_id).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->string('origin', 20)->nullable()->after('legacy_id');
        });
    }

    public function down(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->dropColumn('origin');
        });
    }
};
