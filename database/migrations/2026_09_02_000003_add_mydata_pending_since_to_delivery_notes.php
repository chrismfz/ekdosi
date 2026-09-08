<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * MYD-021: give delivery notes the in-doubt marker invoices already have.
 *
 * A 9.x δελτίο is filed through the same AADE channel and is just as legally
 * binding, but `DeliveryNoteSubmitter` had no lock, no pending marker and no
 * adopt-or-file recovery — two concurrent requests, or one ambiguous response,
 * could produce two AADE documents for one local note.
 *
 * Same column name and semantics as `invoices.mydata_pending_since`: set before
 * the POST, cleared on success or on an outcome that proves no MARK was created.
 * `mydata_state` stays untouched so every existing "is it filed?" predicate is
 * unchanged.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('delivery_notes', 'mydata_pending_since')) {
            return;
        }

        Schema::table('delivery_notes', function (Blueprint $table): void {
            $table->timestamp('mydata_pending_since')->nullable()->after('mydata_url');
        });
    }

    public function down(): void
    {
        Schema::table('delivery_notes', function (Blueprint $table): void {
            $table->dropColumn('mydata_pending_since');
        });
    }
};
